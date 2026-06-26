<?php

namespace Modular\Connector\Security\Imunify\Jobs;

use Modular\Connector\Security\Facades\Scan;
use Modular\Connector\Security\Imunify\Events\ManagerScanPartUpdated;
use Modular\Connector\Security\Imunify\Events\ManagerScanProgressUpdated;
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
 * On permanent failure (retries exhausted): `failed()` cancels the files flow
 * via `Scan::cancelFiles` so API gets a terminal `manager.scan.cancelled`
 * instead of waiting for events that will never arrive.
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
     * @param string $mrid
     * @param string $name
     * @param int $siteScan
     * @param string $manifestPath
     * @param int $totalItems
     */
    public function __construct($mrid, $name, $siteScan, $manifestPath, $totalItems)
    {
        $this->mrid = $mrid;
        $this->name = $name;
        $this->siteScan = $siteScan;
        $this->manifestPath = $manifestPath;
        $this->totalItems = $totalItems;
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

        try {
            $client = ImunifyClient::make();

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

            // Compute the absolute poll deadline once here so every tick in
            // the re-dispatch chain shares the same horizon. API drives this
            // via the `max_poll_hours` site request field; 24 h fallback when
            // missing/invalid so old payloads keep working unchanged.
            $options = Scan::scanOptions($this->siteScan);
            $maxPollHours = isset($options['max_poll_hours']) && (int)$options['max_poll_hours'] > 0
                ? (int)$options['max_poll_hours']
                : 24;
            $pollDeadlineAt = $now + ($maxPollHours * 3600);

            // Kick off polling. The poll job calls /jobs/{id}/results, submits
            // pending hashes/uploads, and re-dispatches itself until Imunify
            // reports is_complete. Mirrors the reference plugin's process_scan
            // loop, driven by the queue instead of cron/AJAX.
            //
            // `lastWebhookAt = $now` so the first poll inherits the throttle from
            // the "start" emission just above — it won't re-emit progress for
            // 10 minutes unless is_complete fires earlier.
            dispatch(new PollImunifyJob(
                $this->mrid,
                $this->name,
                $this->siteScan,
                $jobId,
                $this->manifestPath,
                $this->totalItems,
                $now,
                $pollDeadlineAt
            ));
        } catch (\Throwable $e) {
            Log::error('SubmitToImunifyJob: Failed', [
                'mrid' => $this->mrid,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
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
     * @return array
     */
    private function buildFileList()
    {
        $files = [];
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

        return $files;
    }

}
