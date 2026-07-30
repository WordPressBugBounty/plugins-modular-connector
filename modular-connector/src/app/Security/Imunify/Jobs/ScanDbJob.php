<?php

namespace Modular\Connector\Security\Imunify\Jobs;

use Modular\Connector\Backups\Iron\Helpers\HasMaxTime;
use Modular\Connector\Security\Facades\Scan;
use Modular\Connector\Security\Imunify\Db\DbTablesDiscoverer;
use Modular\Connector\Security\Imunify\Db\PrescanQueryBuilder;
use Modular\Connector\Security\Imunify\Events\ManagerScanCompleted;
use Modular\Connector\Security\Imunify\Events\ManagerScanPartUpdated;
use Modular\Connector\Security\Imunify\Events\ManagerScanProgressUpdated;
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
 *   - Local errors (DB unreachable, prefix misconfigured, /scan/database/start
 *     or /db-content network errors).
 *   - Server-side failure during the wait phase (/db-status returns failed).
 *
 *   Both routes converge on Scan::cancelDatabase, which fires the single terminal
 *   ManagerScanCancelled::forDatabase event. step=manifest/scan failure events
 *   are not emitted — `cancelled` is the only failure terminal for the DB flow,
 *   mirroring how the file flow surfaces failures.
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
     * @var int
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
     * @param int $offset
     * @param int $totalSubmitted
     * @param bool $submitDone
     */
    public function __construct(
        ScanPart $part,
                 $tableIndex = 0,
                 $columnIndex = 0,
                 $offset = 0,
                 $totalSubmitted = 0,
                 $submitDone = false
    )
    {
        $this->part = $part;
        $this->tableIndex = (int)$tableIndex;
        $this->columnIndex = (int)$columnIndex;
        $this->offset = (int)$offset;
        $this->totalSubmitted = (int)$totalSubmitted;
        $this->submitDone = (bool)$submitDone;
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
                $this->emitManifestDone(count($tables), $this->totalSubmitted);
                $this->waitForResults($client, $jobId, $startTime);

                return;
            }

            [$done, $submitted] = $this->runPrescan($client, $jobId, $tables, $builder, $startTime);

            if ($done) {
                $this->emitManifestDone(count($tables), $submitted);
                $this->waitForResults($client, $jobId, $startTime);
            }
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
     * First execution = no progress through tables yet. After a re-dispatch the
     * indexes are non-zero and we don't want to re-emit step=manifest, in_progress.
     *
     * @return bool
     */
    private function isFirstRun()
    {
        return $this->tableIndex === 0
            && $this->columnIndex === 0
            && $this->offset === 0
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

        // Options live in cache since StartScanJob — see Scan::storeScanOptions.
        // `force_rescan`, `webhook_url` and `webhook_secret` are all meaningful
        // for the DB endpoint (the reference plugin forwards them too).
        $options = Scan::scanOptions($this->part->siteScan);

        $response = $client->startDatabaseScan(
            'wordpress',
            $tablePrefix,
            $tables,
            defined('ABSPATH') ? rtrim(ABSPATH, '/') : null,
            $options
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

                $lastKey = 0;
            }

            $columnIdx = 0;
            $lastKey = 0;
        }

        $this->submitContentSafe($client, $jobId, [], true);

        Log::debug('ScanDbJob: Prescan complete', [
            'mrid' => $this->part->mrid,
            'rows_submitted' => $submitted,
        ]);

        return [true, $submitted];
    }

    /**
     * Poll GET /jobs/{jobId}/db-status until status=completed/failed or the per-tick
     * budget is exhausted. DB scans usually settle in 1-3s server-side, so we try a
     * short in-tick wait first; if Imunify is slower than that we re-dispatch with
     * `submitDone=true` so the next tick keeps waiting without re-submitting.
     *
     * @param ImunifyClient $client
     * @param string $jobId
     * @param float $startTime
     * @return void
     */
    private function waitForResults(ImunifyClient $client, $jobId, $startTime)
    {
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
                // are trying to avoid (it grows with detection volume).
                $results = $client->getDatabaseJobResults($jobId);
                $this->completeScan($results);

                return;
            }

            if ($status === 'failed') {
                $error = isset($response['error']) ? (string)$response['error'] : 'Imunify reported db_scan failure';
                $this->failScan($error);

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
            0,
            $this->totalSubmitted,
            true
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
     * @param array $response Body of /db-status with status=completed.
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
     * @param string $error
     * @return void
     */
    private function failScan($error)
    {
        Log::error('ScanDbJob: db-status reported failure', [
            'mrid' => $this->part->mrid,
            'error' => $error,
        ]);

        Scan::cancelDatabase(
            $this->part->mrid,
            $this->part->name,
            $this->part->siteScan,
            $error
        );
    }

    /**
     * Wrap submitDatabaseContent so a 409 "Job is not accepting content" with
     * status=completed is treated as success (Imunify already moved the job to the
     * scanning phase). Other 4xx/5xx propagate as before.
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
     * @param int $offset
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
            false
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
