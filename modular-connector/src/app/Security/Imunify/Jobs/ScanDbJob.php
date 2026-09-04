<?php

namespace Modular\Connector\Security\Imunify\Jobs;

use Modular\Connector\Backups\Iron\Helpers\HasMaxTime;
use Modular\Connector\Security\Facades\Scan;
use Modular\Connector\Security\ScanManager;
use Modular\Connector\Security\Imunify\Db\DbTablesDiscoverer;
use Modular\Connector\Security\Imunify\Db\PrescanQueryBuilder;
use Modular\Connector\Security\Imunify\Events\ManagerScanCompleted;
use Modular\Connector\Security\Imunify\Events\ManagerScanPartUpdated;
use Modular\Connector\Security\Imunify\Events\ManagerScanProgressUpdated;
use Modular\Connector\Security\Imunify\Exceptions\ImunifyQuotaExceededException;
use Modular\Connector\Security\Imunify\Exceptions\ImunifyUnavailableException;
use Modular\Connector\Security\Imunify\ImunifyClient;
use Modular\Connector\Security\Imunify\ScanPart;
use Modular\ConnectorDependencies\Carbon\Carbon;
use Modular\ConnectorDependencies\GuzzleHttp\Exception\ClientException;
use Modular\ConnectorDependencies\Illuminate\Bus\Queueable;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldQueue;
use Modular\ConnectorDependencies\Illuminate\Foundation\Bus\Dispatchable;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Cache;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Config;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use function Modular\ConnectorDependencies\dispatch;
use function Modular\ConnectorDependencies\event;

/**
 * DB scan flow events (in chronological order):
 *
 *   1. ManagerScanPartUpdated::forDatabase   step=manifest, status=in_progress
 *      → emitted on first run, before discovery.
 *
 *   2. ManagerScanProgressUpdated::startedDatabase
 *      → emitted right after /scan/database/start returns the Imunify job_id.
 *        Carries `imunify_job_id`; only fires once per scan (guarded by
 *        cache-miss in ensureScanStarted, so re-dispatches don't re-emit).
 *
 *   3. ManagerScanPartUpdated::forDatabase   step=manifest, status=done
 *      → emitted after the final batch was sent to Imunify (or immediately
 *        when there is nothing to scan; in that case the flow ends here).
 *
 *   4. ManagerScanCompleted::forDatabase
 *      → terminal success. Emitted once GET /jobs/{id}/db-status returns
 *        status=completed; carries the threats list inline.
 *
 * Failures (mutually exclusive with step 4):
 *   - Local errors (DB unreachable, prefix misconfigured, network errors).
 *   - Imunify quota exhausted (403 quota_exceeded).
 *   - Server-side failure or cancellation during the wait phase (/db-status
 *     returns failed | cancelled).
 *   - The flow deadline (max_poll_hours) elapsing.
 *
 *   All routes converge on Scan::cancelDatabase, which fires the single terminal
 *   ManagerScanCancelled::forDatabase event. step=manifest/scan failure events
 *   are not emitted — `cancelled` is the only failure terminal for the DB flow,
 *   mirroring how the file flow surfaces failures.
 *
 * Not a failure: a 429/503 from Imunify (load shedding, maintenance). Each
 * /db-content batch is scanned inline and a shed batch was not committed, so the
 * job re-dispatches itself with the cursor it had before the batch and resends it
 * whole once Retry-After has passed. The wait is sliced in heartbeat intervals;
 * every slice re-emits the last envelope of the current phase (step=manifest
 * in_progress while prescanning, the startedDatabase progress while waiting on
 * /db-status) so API's stall heuristic stays quiet.
 *
 * Note: there is no `step=scan` event at all in this flow.
 *   - "scan in flight" is signalled by step #2 (progress.updated startedDatabase).
 *   - "scan terminal" is signalled by step #4 (completed) or cancelled.
 *   This mirrors the file flow, which also uses progress.updated + completed/cancelled
 *   without a step=scan state machine.
 *
 * Re-dispatch semantics:
 *   - Prescan phase paginates per (tableIndex, columnIndex, lastKey).
 *   - Wait phase reuses the same constructor with $submitDone=true so discovery and
 *     prescan are skipped — only the db-status loop runs.
 *   - $deadlineAt travels with every re-dispatch so the whole flow is bounded.
 */
class ScanDbJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;
    use HasMaxTime;

    /**
     * @var int
     */
    public $tries = 3;

    /**
     * @var ScanPart
     */
    protected $part;

    /**
     * @var int
     */
    protected $tableIndex;

    /**
     * @var int
     */
    protected $columnIndex;

    /**
     * @var int|string|null Keyset cursor for the current (table, column): the
     *                      last primary-key value already submitted, kept with
     *                      the type the database returned so integer and
     *                      varchar keys both compare correctly. Null = start.
     */
    protected $offset;

    /**
     * @var int
     */
    protected $totalSubmitted;

    /**
     * @var bool When true, skip discovery + prescan and enter the db-status wait
     *           loop directly. Set on re-dispatch after the final submit when the
     *           short in-tick wait did not catch the verdict in time.
     */
    protected $submitDone;

    /**
     * @var int Unix timestamp after which the flow gives up with `poll_timeout`.
     *          0 until the first run computes it via `Scan::pollDeadlineAt()`.
     */
    protected $deadlineAt;

    /**
     * @var int Unix timestamp of an Imunify Retry-After still in force; 0 when none.
     */
    protected $busyUntil;

    /**
     * @var int Unix timestamp of the last heartbeat emitted while waiting.
     */
    protected $lastHeartbeatAt;

    /**
     * @var int Aligned with Backup's manifest budget. Re-dispatches with
     *          (tableIndex, columnIndex, lastKey, totalSubmitted) when exceeded.
     */
    protected $maxTime = 25;

    /**
     * @var int Cap for the wait-for-results loop inside a single tick. Leaves
     *          ~5s headroom under $maxTime for the surrounding work (submit,
     *          discovery cache write, event emission).
     */
    private const RESULTS_WAIT_BUDGET = 20;

    /**
     * @var int Sleep between db-status polls during the wait loop.
     */
    private const RESULTS_POLL_INTERVAL = 1;

    /**
     * @var int
     */
    private $batchSize = 500;

    /**
     * @param ScanPart $part Database-typed scan part carrying mrid, name, siteScan
     *                       and excludedTables.
     * @param int $tableIndex
     * @param int $columnIndex
     * @param int|string|null $offset
     * @param int $totalSubmitted
     * @param bool $submitDone
     * @param int $deadlineAt
     * @param int $busyUntil
     * @param int $lastHeartbeatAt
     */
    public function __construct(
        ScanPart $part,
                 $tableIndex = 0,
                 $columnIndex = 0,
                 $offset = null,
                 $totalSubmitted = 0,
                 $submitDone = false,
                 $deadlineAt = 0,
                 $busyUntil = 0,
                 $lastHeartbeatAt = 0
    )
    {
        $this->part = $part;
        $this->tableIndex = (int)$tableIndex;
        $this->columnIndex = (int)$columnIndex;
        // `0` is the pre-typed-cursor "start" marker of jobs already queued.
        $this->offset = $offset === 0 ? null : $offset;
        $this->totalSubmitted = (int)$totalSubmitted;
        $this->submitDone = (bool)$submitDone;
        $this->deadlineAt = (int)$deadlineAt;
        $this->busyUntil = (int)$busyUntil;
        $this->lastHeartbeatAt = (int)$lastHeartbeatAt;
        $this->queue = 'backups';
    }

    /**
     * @return void
     */
    public function handle()
    {
        if ($this->part->isCancelled()) {
            return;
        }

        if ($this->deadlineAt === 0) {
            $this->deadlineAt = Scan::pollDeadlineAt($this->part->siteScan);
        }

        if (Carbon::now()->timestamp > $this->deadlineAt) {
            Log::warning('ScanDbJob: deadline exceeded, cancelling database flow', [
                'mrid' => $this->part->mrid,
                'deadline_at' => $this->deadlineAt,
            ]);

            Scan::cancelDatabase($this->part->mrid, $this->part->name, $this->part->siteScan, 'poll_timeout');

            return;
        }

        // Still inside an Imunify Retry-After: heartbeat and come back later
        // without calling the provider.
        if ($this->busyUntil > 0) {
            $now = Carbon::now()->timestamp;

            if ($now < $this->busyUntil) {
                $this->heartbeat($now);
                $this->redispatchBusy($this->busyUntil, $this->busyUntil - $now);

                return;
            }

            $this->busyUntil = 0;
        }

        try {
            $startTime = $this->getCurrentTime();
            $client = ImunifyClient::make();

            if ($this->submitDone) {
                // Re-entry after a previous tick exhausted its budget while waiting
                // for db-status to flip to "completed". Discovery + prescan already
                // happened; jump straight to the wait loop.
                $jobId = Cache::get('_scan_db_imunify_job_' . $this->part->siteScan);

                if (empty($jobId)) {
                    throw new \RuntimeException('submitDone re-entry but Imunify job_id is no longer in cache');
                }

                $this->waitForResults($client, $jobId, $startTime);

                return;
            }

            if ($this->isFirstRun()) {
                $this->emitManifestInProgress();
            }

            $tables = $this->resolveTables();

            if (empty($tables)) {
                Log::warning('ScanDbJob: No scannable tables discovered', ['mrid' => $this->part->mrid]);
                // Nothing to scan → close the manifest step. step=scan never starts.
                $this->emitManifestDone(0, 0);

                return;
            }

            [$jobId, $scanConfig] = $this->ensureScanStarted($client, $tables);

            $builder = new PrescanQueryBuilder($scanConfig, $this->batchSize);

            if (empty($builder->getPrescanPatterns())) {
                Log::warning('ScanDbJob: scan_config has no patterns; nothing to prescan locally', [
                    'mrid' => $this->part->mrid,
                ]);

                $this->submitContentSafe($client, $jobId, [], true);
                $this->submitDone = true;
                $this->emitManifestDone(count($tables), $this->totalSubmitted);
                $this->waitForResults($client, $jobId, $startTime);

                return;
            }

            [$done, $submitted] = $this->runPrescan($client, $jobId, $tables, $builder, $startTime);

            if ($done) {
                $this->submitDone = true;
                $this->emitManifestDone(count($tables), $submitted);
                $this->waitForResults($client, $jobId, $startTime);
            }
        } catch (ImunifyUnavailableException $e) {
            $this->redispatchLater($e);
        } catch (ImunifyQuotaExceededException $e) {
            Log::error('ScanDbJob: Imunify quota exceeded', [
                'mrid' => $this->part->mrid,
                'code' => $e->getQuotaCode(),
                'quota' => $e->getQuota(),
            ]);

            Scan::cancelDatabase(
                $this->part->mrid,
                $this->part->name,
                $this->part->siteScan,
                ImunifyQuotaExceededException::CODE_QUOTA_EXCEEDED,
                ['quota' => $e->getQuota(), 'message' => $e->getMessage()]
            );
        } catch (\Throwable $e) {
            Log::error('ScanDbJob: Failed', [
                'mrid' => $this->part->mrid,
                'error' => $e->getMessage(),
            ]);

            Scan::cancelDatabase(
                $this->part->mrid,
                $this->part->name,
                $this->part->siteScan,
                $e->getMessage()
            );
        }
    }

    /**
     * Imunify asked us to back off. The instance fields hold the cursor of the
     * batch that was NOT accepted (`runPrescan` refreshes them before every
     * submit), so re-dispatching with them resends exactly that batch.
     *
     * @param ImunifyUnavailableException $e
     * @return void
     */
    private function redispatchLater(ImunifyUnavailableException $e)
    {
        $now = Carbon::now()->timestamp;
        $busyUntil = $now + $e->delaySeconds();

        Log::warning('ScanDbJob: Imunify busy, retrying later', array_merge([
            'mrid' => $this->part->mrid,
            'table_index' => $this->tableIndex,
            'column_index' => $this->columnIndex,
            'offset' => $this->offset,
            'submit_done' => $this->submitDone,
            'busy_until' => $busyUntil,
        ], $e->context()));

        $this->heartbeat($now);
        $this->redispatchBusy($busyUntil, $e->delaySeconds());
    }

    /**
     * Re-queue with the current cursor after at most one heartbeat interval.
     *
     * @param int $busyUntil
     * @param int $remaining Seconds left on the Retry-After.
     * @return void
     */
    private function redispatchBusy($busyUntil, $remaining)
    {
        dispatch(new self(
            $this->part,
            $this->submitDone ? 0 : $this->tableIndex,
            $this->submitDone ? 0 : $this->columnIndex,
            $this->submitDone ? null : $this->offset,
            $this->totalSubmitted,
            $this->submitDone,
            $this->deadlineAt,
            $busyUntil,
            $this->lastHeartbeatAt
        ))->delay(max(1, min($remaining, ScanManager::HEARTBEAT_INTERVAL_SECONDS)));
    }

    /**
     * "Still alive" envelope while waiting out a Retry-After, throttled to one
     * per heartbeat interval. Re-emits the last envelope of the current phase so
     * API's handlers see nothing new: `step=manifest in_progress` while the
     * prescan is still submitting, the `startedDatabase` progress (same
     * `imunify_job_id`) once the final batch is in and we wait on /db-status.
     *
     * @param int $now Unix timestamp.
     * @return void
     */
    private function heartbeat($now)
    {
        if ($this->lastHeartbeatAt > 0 && ($now - $this->lastHeartbeatAt) < ScanManager::HEARTBEAT_INTERVAL_SECONDS) {
            return;
        }

        $jobId = $this->submitDone ? Cache::get('_scan_db_imunify_job_' . $this->part->siteScan) : null;

        if (!empty($jobId)) {
            event(ManagerScanProgressUpdated::startedDatabase(
                $this->part->mrid,
                $this->part->siteScan,
                $jobId
            ));
        } else {
            $this->emitManifestInProgress();
        }

        $this->lastHeartbeatAt = $now;
    }

    /**
     * First execution = no progress through tables yet. After a re-dispatch the
     * indexes are non-zero and we don't want to re-emit step=manifest, in_progress.
     *
     * @return bool
     */
    private function isFirstRun()
    {
        return $this->tableIndex === 0
            && $this->columnIndex === 0
            && $this->offset === null
            && $this->totalSubmitted === 0;
    }

    /**
     * @return array
     */
    private function resolveTables()
    {
        $cacheKey = '_scan_db_tables_' . $this->part->siteScan;
        $cached = Cache::get($cacheKey);

        if ($cached) {
            return $cached;
        }

        $database = Config::get('database.connections.wordpress.database');
        $tablePrefix = Config::get('database.connections.wordpress.prefix', 'wp_');

        if (empty($database)) {
            throw new \RuntimeException('Cannot resolve WordPress database name');
        }

        $tables = DbTablesDiscoverer::discover(
            $database,
            $tablePrefix,
            $this->part->excludedTables
        );

        Cache::put($cacheKey, $tables, Carbon::now()->addDay());

        return $tables;
    }

    /**
     * Start the Imunify DB scan once and cache the job id + scan_config so
     * subsequent re-dispatches can resume without re-starting on the backend.
     *
     * @param ImunifyClient $client
     * @param array $tables
     * @return array [jobId, scanConfig]
     */
    private function ensureScanStarted(ImunifyClient $client, array $tables)
    {
        $jobIdKey = '_scan_db_imunify_job_' . $this->part->siteScan;
        $scanConfigKey = '_scan_db_scan_config_' . $this->part->siteScan;

        $jobId = Cache::get($jobIdKey);
        $scanConfig = Cache::get($scanConfigKey);

        if ($jobId && is_array($scanConfig)) {
            return [$jobId, $scanConfig];
        }

        $tablePrefix = Config::get('database.connections.wordpress.prefix', 'wp_');

        // Only what the reference SDK sends: `force_rescan` and the webhook
        // fields belong to `/scan/start`, the DB endpoint ignores them.
        $response = $client->startDatabaseScan(
            'wordpress',
            $tablePrefix,
            $tables,
            defined('ABSPATH') ? rtrim(ABSPATH, '/') : null
        );

        if (empty($response['job_id'])) {
            throw new \RuntimeException('Imunify did not return a job_id for DB scan');
        }

        $jobId = $response['job_id'];
        $scanConfig = isset($response['scan_config']) && is_array($response['scan_config'])
            ? $response['scan_config']
            : [];

        Cache::put($jobIdKey, $jobId, Carbon::now()->addDay());
        Cache::put($scanConfigKey, $scanConfig, Carbon::now()->addDay());

        // Broadcast the binding once. Guarded by the cache-miss branch above, so
        // the event fires only on the first ensureScanStarted call for a given
        // siteScan — re-dispatches (dispatchContinue, submitDone=true) hit the
        // cached path and skip this.
        event(ManagerScanProgressUpdated::startedDatabase(
            $this->part->mrid,
            $this->part->siteScan,
            $jobId
        ));

        Log::debug('ScanDbJob: Imunify DB scan started', [
            'mrid' => $this->part->mrid,
            'imunify_job_id' => $jobId,
            'tables' => count($tables),
            'patterns' => isset($scanConfig['prescan_patterns']) ? count($scanConfig['prescan_patterns']) : 0,
        ]);

        return [$jobId, $scanConfig];
    }

    /**
     * Iterate through (table, column) combinations resuming from current offsets.
     * Submit suspicious content in batches; respect the 25s budget by re-dispatching
     * with the latest position when exceeded.
     *
     * The instance cursor (`tableIndex`, `columnIndex`, `offset`, `totalSubmitted`)
     * is refreshed right before every submit so a busy answer mid-loop can
     * re-dispatch from the batch that was not accepted.
     *
     * @param ImunifyClient $client
     * @param string $jobId
     * @param array $tables
     * @param PrescanQueryBuilder $builder
     * @param float $startTime
     * @return array [bool $done, int $submitted] — $done=true when the final batch
     *               was sent; false when the iteration was paused with a re-dispatch.
     */
    private function runPrescan(ImunifyClient $client, $jobId, array $tables, PrescanQueryBuilder $builder, $startTime)
    {
        $tableIdx = $this->tableIndex;
        $columnIdx = $this->columnIndex;
        // Cursor pagination: `lastKey` is the row_id of the last row processed for
        // the current (table, column). Builder yields `WHERE key > lastKey`.
        $lastKey = $this->offset;
        $submitted = $this->totalSubmitted;

        for (; $tableIdx < count($tables); $tableIdx++) {
            $table = $tables[$tableIdx];
            $columns = $table['columns'];
            $isBase64 = !empty($table['base64']);
            $rowFilter = isset($table['row_filter']) && is_array($table['row_filter'])
                ? $table['row_filter']
                : [];

            for (; $columnIdx < count($columns); $columnIdx++) {
                $column = $columns[$columnIdx];

                $generator = $builder->executePrescan(
                    $table['name'],
                    $column,
                    $table['key'],
                    $isBase64,
                    $lastKey,
                    $rowFilter
                );

                foreach ($generator as $batch) {
                    $this->rememberCursor($tableIdx, $columnIdx, $lastKey, $submitted);

                    if (!$this->submitContentSafe($client, $jobId, $batch, false)) {
                        // Imunify reports the job as already completed (e.g. a previous
                        // run sent the final batch and the queue is retrying with the
                        // original constructor args). Stop here, the scan is in flight.
                        return [true, $submitted];
                    }
                    $submitted += count($batch);
                    $last = end($batch);
                    $lastKey = $last['row_id'];

                    if ($this->isTimeExceeded($startTime, $this->maxTime)) {
                        $this->dispatchContinue($tableIdx, $columnIdx, $lastKey, $submitted);

                        return [false, $submitted];
                    }
                }

                $lastKey = null;
            }

            $columnIdx = 0;
            $lastKey = null;
        }

        $this->rememberCursor(count($tables), 0, null, $submitted);
        $this->submitContentSafe($client, $jobId, [], true);

        Log::debug('ScanDbJob: Prescan complete', [
            'mrid' => $this->part->mrid,
            'rows_submitted' => $submitted,
        ]);

        return [true, $submitted];
    }

    /**
     * @param int $tableIdx
     * @param int $columnIdx
     * @param int|string|null $lastKey Kept as returned by the database (typed cursor).
     * @param int $submitted
     * @return void
     */
    private function rememberCursor($tableIdx, $columnIdx, $lastKey, $submitted)
    {
        $this->tableIndex = (int)$tableIdx;
        $this->columnIndex = (int)$columnIdx;
        $this->offset = $lastKey;
        $this->totalSubmitted = (int)$submitted;
    }

    /**
     * Poll GET /jobs/{jobId}/db-status until a terminal state or the per-tick
     * budget is exhausted. DB scans usually settle in 1-3s server-side, so we try
     * a short in-tick wait first; if Imunify is slower than that we re-dispatch
     * with `submitDone=true` so the next tick keeps waiting without re-submitting.
     *
     * Terminal states: `completed` (fetch results), `failed` and `cancelled`
     * (operator cancel or Imunify's stuck-job watchdog) — both cancel the flow.
     *
     * @param ImunifyClient $client
     * @param string $jobId
     * @param float $startTime
     * @return void
     */
    private function waitForResults(ImunifyClient $client, $jobId, $startTime)
    {
        $this->submitDone = true;

        $deadline = min(
            $startTime + self::RESULTS_WAIT_BUDGET,
            $startTime + ($this->maxTime - 1)
        );

        while ($this->getCurrentTime() < $deadline) {
            $response = $client->getDatabaseJobStatus($jobId);
            $status = isset($response['status']) ? (string)$response['status'] : '';

            if ($status === 'completed') {
                // /db-status omits the threats list; fetch it once now that the
                // job is terminal. Polling that endpoint repeatedly is what we
                // are trying to avoid (it grows with detection volume), and it
                // paginates — hence the walk-every-page variant.
                $results = $client->getAllDatabaseJobResults($jobId);
                $this->completeScan($results);

                return;
            }

            if ($status === 'failed' || $status === 'cancelled') {
                $error = isset($response['error']) && $response['error'] !== ''
                    ? (string)$response['error']
                    : ($status === 'cancelled' ? 'imunify_job_cancelled' : 'Imunify reported db_scan failure');

                $this->failScan($status, $error);

                return;
            }

            sleep(self::RESULTS_POLL_INTERVAL);
        }

        Log::debug('ScanDbJob: db-status not ready within tick budget, redispatching', [
            'mrid' => $this->part->mrid,
            'imunify_job_id' => $jobId,
        ]);

        dispatch(new self(
            $this->part,
            0,
            0,
            null,
            $this->totalSubmitted,
            true,
            $this->deadlineAt
        ));
    }

    /**
     * Terminal success: emit ManagerScanCompleted::forDatabase with the threats
     * inline. ScanCleanupListener reacts to the event and wipes the per-scan DB
     * caches so a follow-up scan starts clean.
     *
     * No step=scan event is emitted — `completed` is the single terminal event
     * for the DB flow, mirroring how the file flow uses `completed` without a
     * preceding step=scan done. See class docblock for the full event sequence.
     *
     * @param array $response Body of /db-results (every page merged).
     * @return void
     */
    private function completeScan(array $response)
    {
        $summary = isset($response['summary']) && is_array($response['summary'])
            ? $response['summary']
            : [];
        $results = isset($response['results']) && is_array($response['results'])
            ? $response['results']
            : [];

        $tablesScanned = isset($summary['tables_scanned']) ? (int)$summary['tables_scanned'] : 0;
        $rowsSubmitted = isset($summary['rows_submitted']) ? (int)$summary['rows_submitted'] : 0;
        $threatsFound = count($results);

        event(ManagerScanCompleted::forDatabase($this->part->mrid, $this->part->siteScan, [
            'tables_scanned' => $tablesScanned,
            'rows_submitted' => $rowsSubmitted,
            'threats_found' => $threatsFound,
            'threats' => $results,
        ]));

        Log::debug('ScanDbJob: DB scan completed', [
            'mrid' => $this->part->mrid,
            'tables_scanned' => $tablesScanned,
            'rows_submitted' => $rowsSubmitted,
            'threats_found' => $threatsFound,
        ]);
    }

    /**
     * Terminal failure during the wait phase. Routes to Scan::cancelDatabase so
     * the API receives a single `manager.scan.cancelled` (type=database) event —
     * mirroring how PollImunifyJob handles Imunify-side failures for files.
     *
     * @param string $status `failed` | `cancelled`
     * @param string $error
     * @return void
     */
    private function failScan($status, $error)
    {
        Log::error('ScanDbJob: db-status reported a terminal failure', [
            'mrid' => $this->part->mrid,
            'status' => $status,
            'error' => $error,
        ]);

        Scan::cancelDatabase(
            $this->part->mrid,
            $this->part->name,
            $this->part->siteScan,
            $error,
            ['imunify_status' => $status]
        );
    }

    /**
     * Wrap submitDatabaseContent so a 409 "Job is not accepting content" with
     * status=completed is treated as success (Imunify already moved the job to the
     * scanning phase). Other 4xx/5xx propagate as before; 429/503 propagate as
     * ImunifyUnavailableException for the busy re-dispatch.
     *
     * @param ImunifyClient $client
     * @param string $jobId
     * @param array $rows
     * @param bool $isFinal
     * @return bool true when the call succeeded; false when Imunify already completed the job.
     */
    private function submitContentSafe(ImunifyClient $client, $jobId, array $rows, $isFinal)
    {
        try {
            $client->submitDatabaseContent($jobId, $rows, $isFinal);

            return true;
        } catch (ClientException $e) {
            if ($this->isJobAlreadyCompleted($e)) {
                Log::debug('ScanDbJob: Imunify reports job already completed; skipping further submissions', [
                    'mrid' => $this->part->mrid,
                    'imunify_job_id' => $jobId,
                ]);

                return false;
            }

            throw $e;
        }
    }

    /**
     * @param ClientException $e
     * @return bool
     */
    private function isJobAlreadyCompleted(ClientException $e)
    {
        $response = $e->getResponse();

        if (!$response || $response->getStatusCode() !== 409) {
            return false;
        }

        $data = json_decode((string)$response->getBody(), true);

        return is_array($data) && isset($data['status']) && $data['status'] === 'completed';
    }

    /**
     * @param int $tableIdx
     * @param int $columnIdx
     * @param int|string|null $offset Typed keyset cursor.
     * @param int $submitted
     * @return void
     */
    private function dispatchContinue($tableIdx, $columnIdx, $offset, $submitted)
    {
        Log::debug('ScanDbJob: Time exceeded, redispatching', [
            'mrid' => $this->part->mrid,
            'table_index' => $tableIdx,
            'column_index' => $columnIdx,
            'offset' => $offset,
            'submitted' => $submitted,
        ]);

        dispatch(new self(
            $this->part,
            $tableIdx,
            $columnIdx,
            $offset,
            $submitted,
            false,
            $this->deadlineAt
        ));
    }

    /**
     * @return void
     */
    private function emitManifestInProgress()
    {
        event(ManagerScanPartUpdated::forDatabase(
            $this->part->mrid,
            $this->part->siteScan,
            ManagerScanPartUpdated::STEP_MANIFEST,
            ManagerScanPartUpdated::STATUS_IN_PROGRESS
        ));
    }

    /**
     * @param int $tablesCount
     * @param int $rowsSubmitted
     * @return void
     */
    private function emitManifestDone($tablesCount, $rowsSubmitted)
    {
        event(ManagerScanPartUpdated::forDatabase(
            $this->part->mrid,
            $this->part->siteScan,
            ManagerScanPartUpdated::STEP_MANIFEST,
            ManagerScanPartUpdated::STATUS_DONE,
            [
                'tables_scanned' => $tablesCount,
                'rows_submitted' => $rowsSubmitted,
            ]
        ));
    }

}
