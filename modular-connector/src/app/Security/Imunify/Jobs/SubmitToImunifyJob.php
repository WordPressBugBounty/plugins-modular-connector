<?php

namespace Modular\Connector\Security\Imunify\Jobs;

use Modular\Connector\Security\Facades\Scan;
use Modular\Connector\Security\ScanManager;
use Modular\Connector\Security\Imunify\Events\ManagerScanPartUpdated;
use Modular\Connector\Security\Imunify\Events\ManagerScanProgressUpdated;
use Modular\Connector\Security\Imunify\Exceptions\ImunifyQuotaExceededException;
use Modular\Connector\Security\Imunify\Exceptions\ImunifyUnavailableException;
use Modular\Connector\Security\Imunify\Helpers\ConsolidatedManifest;
use Modular\Connector\Security\Imunify\ImunifyClient;
use Modular\ConnectorDependencies\Carbon\Carbon;
use Modular\ConnectorDependencies\Illuminate\Bus\Queueable;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldQueue;
use Modular\ConnectorDependencies\Illuminate\Foundation\Bus\Dispatchable;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Cache;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Storage;
use function Modular\ConnectorDependencies\dispatch;
use function Modular\ConnectorDependencies\event;

/**
 * Submits the consolidated manifest to Imunify via POST /api/v1/scan/start.
 *
 * On success:
 *   - Emits `manager.scan.progress.updated` with `status=in_progress` (the "start"
 *     of the scan-progress lifecycle, with initial counts: all files pending sha256).
 *   - Dispatches `PollImunifyJob` carrying the timestamp of the start emission so
 *     the first poll inherits the throttle window (no duplicate progress emit).
 *
 * On a 429/503 from Imunify (load shedding, maintenance) the job re-dispatches
 * itself in slices of ScanManager::HEARTBEAT_INTERVAL_SECONDS until the
 * server-stated Retry-After has passed — no job was created, so nothing is lost.
 * Every slice emits a `manager.scan.progress.updated` heartbeat (all files
 * pending, no `imunify_job_id` yet) so API's stall heuristic stays quiet. The
 * same deadline that bounds polling bounds these retries.
 *
 * On a quota 403 the files flow is cancelled right away with the `quota_exceeded`
 * tag and the quota counters, so API can show when it resets.
 *
 * On any other permanent failure (retries exhausted): `failed()` cancels the
 * files flow via `Scan::cancelFiles` so API gets a terminal
 * `manager.scan.cancelled` instead of waiting for events that will never arrive.
 *
 * The local merge is reported by its own dedicated event
 * (`ManagerScanFilesMergeUpdated`) emitted by `MergeScanManifestsJob`.
 *
 * SHA256 submissions and file uploads to Imunify do NOT happen in this job — the
 * polling loop in `PollImunifyJob` handles both, mirroring the reference plugin's
 * scanner.php::process_scan pattern.
 */
class SubmitToImunifyJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    /**
     * @var int
     */
    public $tries = 3;

    /**
     * @var int Seconds between attempts after an uncaught exception, so a
     *          transport blip is not retried three times within one second.
     */
    public $backoff = 60;

    /**
     * @var int `/scan/start` carries the whole inventory in one POST, so it gets
     *          the reference plugin's longer timeout instead of the 30 s used
     *          for polling.
     */
    private const START_TIMEOUT_SECONDS = 60;

    /**
     * @var string
     */
    protected $mrid;

    /**
     * @var string
     */
    protected $name;

    /**
     * @var int
     */
    protected $siteScan;

    /**
     * @var string
     */
    protected $manifestPath;

    /**
     * @var int
     */
    protected $totalItems;

    /**
     * @var int Unix timestamp bounding both the busy retries of this job and
     *          the poll chain it starts. 0 until the first attempt computes it
     *          from `max_poll_hours`, then carried across re-dispatches.
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
     * @param string $mrid
     * @param string $name
     * @param int $siteScan
     * @param string $manifestPath
     * @param int $totalItems
     * @param int $deadlineAt
     * @param int $busyUntil
     * @param int $lastHeartbeatAt
     */
    public function __construct(
        $mrid,
        $name,
        $siteScan,
        $manifestPath,
        $totalItems,
        $deadlineAt = 0,
        $busyUntil = 0,
        $lastHeartbeatAt = 0
    )
    {
        $this->mrid = $mrid;
        $this->name = $name;
        $this->siteScan = $siteScan;
        $this->manifestPath = $manifestPath;
        $this->totalItems = $totalItems;
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
        if (Scan::isFilesCancelled($this->name)) {
            return;
        }

        if (!Storage::disk('scans')->exists($this->manifestPath)) {
            Log::error('SubmitToImunifyJob: Consolidated manifest missing', [
                'mrid' => $this->mrid,
                'path' => $this->manifestPath,
            ]);

            Scan::cancelFiles(
                $this->mrid,
                $this->name,
                $this->siteScan,
                'consolidated_manifest_missing'
            );

            return;
        }

        // Compute the absolute deadline once so every busy retry here and
        // every tick of the poll chain share the same horizon. API drives it
        // via the `max_poll_hours` site request field; 24 h fallback when
        // missing/invalid so old payloads keep working unchanged.
        if ($this->deadlineAt === 0) {
            $this->deadlineAt = Scan::pollDeadlineAt($this->siteScan);
        }

        if (Carbon::now()->timestamp > $this->deadlineAt) {
            Log::warning('SubmitToImunifyJob: deadline exceeded before Imunify accepted the scan', [
                'mrid' => $this->mrid,
                'deadline_at' => $this->deadlineAt,
            ]);

            Scan::cancelFiles($this->mrid, $this->name, $this->siteScan, 'poll_timeout');

            return;
        }

        // Still inside an Imunify Retry-After: heartbeat and come back later
        // without calling the provider.
        if ($this->busyUntil > 0) {
            $now = Carbon::now()->timestamp;

            if ($now < $this->busyUntil) {
                $this->heartbeat($now);
                $this->redispatch($this->busyUntil, $this->busyUntil - $now);

                return;
            }

            $this->busyUntil = 0;
        }

        try {
            $client = ImunifyClient::make(self::START_TIMEOUT_SECONDS);

            $jobId = $this->ensureScanStarted($client);

            // Emit the "start" progress webhook here, not from the first poll —
            // /scan/start has just returned the job_id, so API gets an immediate
            // signal that the scan is running (with the provider's id to persist)
            // without waiting for the queue to pick up PollImunifyJob (≈1 min
            // with wp-cron). This is the only emission carrying `imunify_job_id`.
            $now = Carbon::now()->timestamp;

            event(ManagerScanProgressUpdated::started(
                $this->mrid,
                $this->siteScan,
                ManagerScanPartUpdated::TYPE_FILES,
                $jobId,
                $this->totalItems
            ));

            // Kick off polling. The poll job calls /jobs/{id}/results, submits
            // pending hashes/uploads, and re-dispatches itself until Imunify
            // reports is_complete. Mirrors the reference plugin's process_scan
            // loop, driven by the queue instead of cron/AJAX.
            //
            // `lastWebhookAt = $now` so the first poll inherits the throttle from
            // the "start" emission just above — it won't re-emit progress for
            // PROGRESS_WEBHOOK_THROTTLE_SECONDS unless is_complete fires earlier.
            dispatch(new PollImunifyJob(
                $this->mrid,
                $this->name,
                $this->siteScan,
                $jobId,
                $this->manifestPath,
                $this->totalItems,
                $now,
                $this->deadlineAt
            ));
        } catch (ImunifyUnavailableException $e) {
            $now = Carbon::now()->timestamp;
            $busyUntil = $now + $e->delaySeconds();

            Log::warning('SubmitToImunifyJob: Imunify busy, retrying later', array_merge([
                'mrid' => $this->mrid,
                'busy_until' => $busyUntil,
            ], $e->context()));

            $this->heartbeat($now);
            $this->redispatch($busyUntil, $e->delaySeconds());
        } catch (ImunifyQuotaExceededException $e) {
            Log::error('SubmitToImunifyJob: Imunify quota exceeded', [
                'mrid' => $this->mrid,
                'code' => $e->getQuotaCode(),
                'quota' => $e->getQuota(),
            ]);

            Scan::cancelFiles(
                $this->mrid,
                $this->name,
                $this->siteScan,
                ImunifyQuotaExceededException::CODE_QUOTA_EXCEEDED,
                ['quota' => $e->getQuota(), 'message' => $e->getMessage()]
            );
        } catch (\Throwable $e) {
            Log::error('SubmitToImunifyJob: Failed', [
                'mrid' => $this->mrid,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Re-queue this submit after at most one heartbeat interval, remembering
     * until when Imunify asked us to wait.
     *
     * @param int $busyUntil
     * @param int $remaining Seconds left on the Retry-After.
     * @return void
     */
    private function redispatch($busyUntil, $remaining)
    {
        dispatch(new self(
            $this->mrid,
            $this->name,
            $this->siteScan,
            $this->manifestPath,
            $this->totalItems,
            $this->deadlineAt,
            $busyUntil,
            $this->lastHeartbeatAt
        ))->delay(max(1, min($remaining, ScanManager::HEARTBEAT_INTERVAL_SECONDS)));
    }

    /**
     * "Still alive" envelope while waiting out a Retry-After, throttled to one
     * per heartbeat interval. Reuses `manager.scan.progress.updated` with the
     * trivial pre-start stats (every file pending, no verdicts) and no
     * `imunify_job_id`, since `/scan/start` has not been accepted yet.
     *
     * @param int $now Unix timestamp.
     * @return void
     */
    private function heartbeat($now)
    {
        if ($this->lastHeartbeatAt > 0 && ($now - $this->lastHeartbeatAt) < ScanManager::HEARTBEAT_INTERVAL_SECONDS) {
            return;
        }

        event(ManagerScanProgressUpdated::inProgress(
            $this->mrid,
            $this->siteScan,
            ManagerScanPartUpdated::TYPE_FILES,
            $this->totalItems,
            $this->totalItems,
            0,
            0
        ));

        $this->lastHeartbeatAt = $now;
    }

    /**
     * Queue-worker callback after the final retry has failed. Cancels the files
     * flow so API receives a terminal `manager.scan.cancelled` and
     * ScanCleanupListener wipes the per-flow cache.
     *
     * @param \Throwable|null $e
     * @return void
     */
    public function failed(\Throwable $e = null)
    {
        Scan::cancelFiles(
            $this->mrid,
            $this->name,
            $this->siteScan,
            $e ? $e->getMessage() : 'submit_failed'
        );
    }

    /**
     * Start the Imunify scan job if not already started, return the job id.
     *
     * @param ImunifyClient $client
     * @return string
     */
    private function ensureScanStarted(ImunifyClient $client)
    {
        $cacheKey = '_scan_imunify_job_' . $this->siteScan;
        $jobId = Cache::get($cacheKey);

        if ($jobId) {
            return $jobId;
        }

        $files = $this->buildFileList();

        // Options (force_rescan, webhook_url, webhook_secret) live in cache
        // since StartScanJob — see Scan::storeScanOptions. Empty array when
        // none were supplied, which the client treats as defaults.
        $response = $client->startScan($files, Scan::scanOptions($this->siteScan));

        if (empty($response['job_id'])) {
            throw new \RuntimeException('Imunify did not return a job_id');
        }

        $jobId = $response['job_id'];

        Cache::put($cacheKey, $jobId, Carbon::now()->addDay());

        Log::debug('SubmitToImunifyJob: Imunify scan started', [
            'mrid' => $this->mrid,
            'imunify_job_id' => $jobId,
            'files' => count($files),
        ]);

        return $jobId;
    }

    /**
     * Build the file list for /scan/start from the consolidated manifest.
     *
     * The consolidated manifest stores paths as absolute filesystem paths
     * already (resolved via the Storage facade at consolidation time), so
     * `resolveSafePath()` here is purely a defensive guard — it re-validates
     * traversal and disk-root containment at the FS boundary before the path
     * goes on the wire to Imunify.
     *
     * Paths are deduplicated: the backend bulk-upserts on `(site_id, path)`
     * and rejects a manifest that carries the same path twice, which can
     * happen when custom disks overlap. The first index wins; a dropped
     * duplicate simply never shows up in `/results`.
     *
     * @return array
     */
    private function buildFileList()
    {
        $files = [];
        $seen = [];
        $duplicates = 0;
        $path = Storage::disk('scans')->path($this->manifestPath);
        $handle = fopen($path, 'r');

        if (!$handle) {
            throw new \RuntimeException('Cannot open consolidated manifest');
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $row = ConsolidatedManifest::parseRow($line);
                if ($row === null) {
                    continue;
                }

                try {
                    $absolute = ConsolidatedManifest::resolveSafePath($row['path']);
                } catch (\Throwable $e) {
                    Log::warning('SubmitToImunifyJob: Path validation failed', [
                        'mrid' => $this->mrid,
                        'path' => $row['path'],
                        'error' => $e->getMessage(),
                    ]);

                    continue;
                }

                if (isset($seen[$absolute])) {
                    $duplicates++;

                    continue;
                }

                $seen[$absolute] = true;

                $files[] = [
                    'path' => $absolute,
                    'file_index' => (int)$row['file_index'],
                    'size' => (int)$row['size'],
                    'modified_at' => (int)$row['timestamp'],
                ];
            }
        } finally {
            fclose($handle);
        }

        if ($duplicates > 0) {
            Log::warning('SubmitToImunifyJob: Dropped duplicate paths from the manifest', [
                'mrid' => $this->mrid,
                'duplicates' => $duplicates,
            ]);
        }

        return $files;
    }
}
