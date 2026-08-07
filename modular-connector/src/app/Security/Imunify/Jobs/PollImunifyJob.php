<?php

namespace Modular\Connector\Security\Imunify\Jobs;

use Modular\Connector\Backups\Iron\Helpers\HasMaxTime;
use Modular\Connector\Security\Facades\Scan;
use Modular\Connector\Security\Imunify\Events\ManagerScanPartUpdated;
use Modular\Connector\Security\Imunify\Events\ManagerScanProgressUpdated;
use Modular\Connector\Security\Imunify\Helpers\ConsolidatedManifest;
use Modular\Connector\Security\Imunify\ImunifyClient;
use Modular\ConnectorDependencies\Carbon\Carbon;
use Modular\ConnectorDependencies\Illuminate\Bus\Queueable;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldQueue;
use Modular\ConnectorDependencies\Illuminate\Foundation\Bus\Dispatchable;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Storage;
use function Modular\ConnectorDependencies\dispatch;
use function Modular\ConnectorDependencies\event;

/**
 * Drives the Imunify scan by polling, replacing the webhook-driven design.
 *
 * Pattern (mirrors the reference plugin imunify-malware-scanner/scanner.php::process_scan):
 *   1. GET /jobs/{id}/results — returns the full file list with per-file `action`.
 *   2. Iterate `files`:
 *        - `action=needs_sha256` → batched POSTs to /jobs/{id}/hashes (50 per batch,
 *          as many batches as the tick budget allows)
 *        - `action=needs_upload` → grouped multipart POSTs to
 *          /jobs/{id}/upload-batch (10 files or 5 MB per request), with a
 *          per-file /jobs/{id}/upload fallback when a batch is refused
 *   3. If `is_complete` → emit `ManagerScanCompleted` and stop.
 *   4. Else → emit `ManagerScanProgressUpdated::inProgress` (throttled) and re-dispatch.
 *
 * Imunify's response IS the work queue: files needing action carry the action tag,
 * processed files don't. The plugin never tracks "what I've already submitted"
 * locally — it trusts Imunify's view of state on every poll.
 *
 * SHA256 values come from the consolidated NDJSON manifest (precomputed by
 * ScanManifest) — no per-file re-hashing on disk.
 */
class PollImunifyJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable;
    use Queueable;
    use HasMaxTime;

    /**
     * @var int
     */
    public $tries = 3;

    /**
     * @var int TTL (seconds) for the unique lock. Long enough to span the
     *          typical inter-tick window (wp-cron firing) so a queued-but-not-
     *          yet-processed tick keeps the lock; short enough that if the
     *          chain dies, the kick endpoint can revive it after this window.
     */
    public $uniqueFor = 600;

    /**
     * @var int Wall-clock budget per tick, aligned with Backup's manifest/merge
     *          jobs. Bounds how many `/upload-batch` requests we squeeze into a
     *          single poll; whatever is left stays `needs_upload` and the next
     *          tick picks it up.
     */
    protected $maxTime = 25;

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
    protected $imunifyJobId;

    /**
     * @var string
     */
    protected $manifestPath;

    /**
     * @var int Total files in the consolidated manifest — propagated to the
     *          `done` event for API.
     */
    protected $totalItems;

    /**
     * @var int Unix timestamp of the last `ManagerScanProgressUpdated` webhook
     *          emitted in this poll chain. Travels in the constructor across
     *          re-dispatches so the throttle window survives the queue payload.
     *          `0` means "no webhook emitted yet" → next poll emits the "start".
     */
    protected $lastWebhookAt;

    /**
     * @var int Unix timestamp after which the poll chain auto-cancels with
     *          motive `poll_timeout`. Hard upper bound against Imunify never
     *          flipping `is_complete`. Configured per scan by API via the
     *          `max_poll_hours` site request field; fallback 24 h applied at
     *          SubmitToImunifyJob when the field is missing or invalid. `0`
     *          means "no deadline" — kept for forward-compat with payloads
     *          that may legitimately want no cap, but no caller produces 0
     *          today.
     */
    protected $pollDeadlineAt;

    /**
     * @var int Matches the reference plugin (BATCH_SIZE = 50).
     */
    private $hashBatchSize = 50;

    /**
     * @var int Files per `/upload-batch` request.
     */
    private const UPLOAD_BATCH_MAX_FILES = 10;

    /**
     * @var int Byte budget per `/upload-batch` request. Independent of
     *          `ScanPart::$maxFileSize` (which API can raise), so a bigger
     *          per-file cap shrinks the batch instead of inflating the request.
     */
    private const UPLOAD_BATCH_MAX_BYTES = 5242880;

    /**
     * @var int Minimum seconds between two consecutive progress webhooks.
     *          2 minutes — short enough for the UI to feel live, long enough
     *          to avoid hammering API on fast-completing scans.
     */
    private const PROGRESS_WEBHOOK_THROTTLE_SECONDS = 120;

    /**
     * @param string $mrid
     * @param string $name
     * @param int $siteScan
     * @param string $imunifyJobId
     * @param string $manifestPath Consolidated manifest path on the `scans` disk.
     * @param int $totalItems
     * @param int $lastWebhookAt Cursor for the progress-webhook throttle. Defaults
     *                           to 0 on the first dispatch (start case).
     * @param int $pollDeadlineAt Unix timestamp upper bound; 0 disables the cap.
     */
    public function __construct(
        $mrid,
        $name,
        $siteScan,
        $imunifyJobId,
        $manifestPath,
        $totalItems,
        $lastWebhookAt = 0,
        $pollDeadlineAt = 0
    )
    {
        $this->mrid = $mrid;
        $this->name = $name;
        $this->siteScan = (int)$siteScan;
        $this->imunifyJobId = (string)$imunifyJobId;
        $this->manifestPath = $manifestPath;
        $this->totalItems = (int)$totalItems;
        $this->lastWebhookAt = (int)$lastWebhookAt;
        $this->pollDeadlineAt = (int)$pollDeadlineAt;
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
            Log::error('PollImunifyJob: Consolidated manifest missing', [
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

        // Bump mtime so AutoCleanUpCommand does not sweep the manifest of a
        // long scan as "orphan". We only read this file during polling — no
        // fwrite to refresh mtime implicitly.
        @touch(Storage::disk('scans')->path($this->manifestPath));

        // Hard ceiling: abort the chain if Imunify never reports is_complete
        // within the budget. Prevents the re-dispatch loop from running
        // forever against a stuck/buggy job on the provider side.
        if ($this->pollDeadlineAt > 0 && Carbon::now()->timestamp > $this->pollDeadlineAt) {
            Log::warning('PollImunifyJob: poll deadline exceeded, cancelling scan', [
                'mrid' => $this->mrid,
                'imunify_job_id' => $this->imunifyJobId,
                'poll_deadline_at' => $this->pollDeadlineAt,
            ]);

            Scan::cancelFiles($this->mrid, $this->name, $this->siteScan, 'poll_timeout');

            return;
        }

        $startTime = $this->getCurrentTime();

        try {
            $client = ImunifyClient::make();

            $results = $client->getJobResults($this->imunifyJobId);
        } catch (\Throwable $e) {
            Log::error('PollImunifyJob: getJobResults failed', [
                'mrid' => $this->mrid,
                'imunify_job_id' => $this->imunifyJobId,
                'error' => $e->getMessage(),
            ]);

            // Let the queue retry ($tries=3). On final failure the failed() handler
            // emits the terminal failure event and cleans up.
            throw $e;
        }

        $files = isset($results['files']) && is_array($results['files']) ? $results['files'] : [];
        $providerStatus = isset($results['status']) ? (string)$results['status'] : '';

        // Imunify can mark the job as terminally failed even before is_complete
        // flips — most commonly its own 1h30 hard timeout, in which case the
        // response still carries partial verdicts in `files[]` for everything
        // analyzed up to that point. Salvage those: emit ManagerScanCompleted
        // with `status=failed` so API gets the findings (e.g. 7 malicious
        // detections lost in a 33k-file scan) instead of a Cancelled event
        // that throws all results away. Only fall back to the legacy cancel
        // path when there is no verdict at all (job died too early).
        if ($providerStatus === 'failed') {
            Log::error('PollImunifyJob: Imunify reports job failed', [
                'mrid' => $this->mrid,
                'imunify_job_id' => $this->imunifyJobId,
                'completed_files' => isset($results['completed_files']) ? (int)$results['completed_files'] : null,
                'total_files' => isset($results['total_files']) ? (int)$results['total_files'] : null,
                'error' => isset($results['error']) ? (string)$results['error'] : null,
            ]);

            if ($this->hasAnyVerdict($files)) {
                $this->emitDone($files, $providerStatus);
                return;
            }

            Scan::cancelFiles(
                $this->mrid,
                $this->name,
                $this->siteScan,
                isset($results['error']) && $results['error'] !== '' ? (string)$results['error'] : 'imunify_job_failed'
            );

            return;
        }

        // Imunify's view is the source of truth on completion. Stop polling.
        // Only ManagerScanCompleted is emitted — it is the richer terminal signal
        // (carries `summary`); ProgressUpdated reports in-flight state only.
        if (!empty($results['is_complete']) && $providerStatus !== 'processing') {
            $this->emitDone($files, $providerStatus);

            return;
        }

        $rowsByIndex = ConsolidatedManifest::rowsByIndex($this->manifestPath);

        // Order matters: hashes first (cheap batch op, unblocks many files at
        // once) then uploads (one multipart POST per file). Both share the
        // same time budget; if hashes consume it all, uploads pick up next
        // tick — that's the correct priority anyway.
        $this->processNeedsSha256($client, $files, $rowsByIndex, $startTime);
        $this->processNeedsUpload($client, $files, $rowsByIndex, $startTime);

        // Emit progress on the first poll (start) and afterwards only every
        // PROGRESS_WEBHOOK_THROTTLE_SECONDS — avoids spamming API on every tick.
        $now = Carbon::now()->timestamp;

        if ($this->shouldEmitProgress($now)) {
            $this->emitProgress($files);
            $this->lastWebhookAt = $now;
        }

        // Re-dispatch for the next poll tick, carrying the updated webhook cursor
        // and the original deadline so the cap survives across runs.
        dispatch(new self(
            $this->mrid,
            $this->name,
            $this->siteScan,
            $this->imunifyJobId,
            $this->manifestPath,
            $this->totalItems,
            $this->lastWebhookAt,
            $this->pollDeadlineAt
        ));
    }

    /**
     * Emit "start" on the first run; afterwards only when the throttle window
     * has elapsed since the previous emit.
     *
     * @param int $now Unix timestamp.
     * @return bool
     */
    private function shouldEmitProgress($now)
    {
        if ($this->lastWebhookAt === 0) {
            return true;
        }

        return ($now - $this->lastWebhookAt) >= self::PROGRESS_WEBHOOK_THROTTLE_SECONDS;
    }

    /**
     * Build counts from Imunify's `files` array and emit
     * `manager.scan.progress.updated` (in-flight progress).
     *
     * @param array $files
     * @return void
     */
    private function emitProgress(array $files)
    {
        $needsSha256 = 0;
        $needsUpload = 0;
        $hasVerdict = 0;

        foreach ($files as $file) {
            $action = isset($file['action']) ? $file['action'] : '';
            switch ($action) {
                case 'needs_sha256':
                    $needsSha256++;
                    break;
                case 'needs_upload':
                    $needsUpload++;
                    break;
                case 'has_verdict':
                    $hasVerdict++;
                    break;
            }
        }

        event(ManagerScanProgressUpdated::inProgress(
            $this->mrid,
            $this->siteScan,
            ManagerScanPartUpdated::TYPE_FILES,
            $this->totalItems,
            $needsSha256,
            $needsUpload,
            $hasVerdict
        ));
    }

    /**
     * Submit SHA256 batches (`hashBatchSize` files per `/jobs/{id}/hashes` POST,
     * matching the API contract) until the per-tick time budget is exhausted.
     * Hashes come from the precomputed manifest, no re-hashing on disk.
     *
     * Same philosophy as `processNeedsUpload`: the reference plugin caps at one
     * batch per AJAX tick because of PHP timeout pressure on the frontend; we
     * run from a queue worker, so we can chain several batches inside a single
     * tick and shrink wall-clock by the same factor.
     *
     * @param ImunifyClient $client
     * @param array $files
     * @param array<int, array> $rowsByIndex
     * @param float $startTime From `getCurrentTime()` at the top of `handle()`.
     * @return void
     */
    private function processNeedsSha256(ImunifyClient $client, array $files, array $rowsByIndex, $startTime)
    {
        $toSubmit = [];
        $totalSubmitted = 0;

        foreach ($files as $file) {
            if ($this->isTimeExceeded($startTime, $this->maxTime)) {
                break;
            }

            if (!isset($file['action']) || $file['action'] !== 'needs_sha256') {
                continue;
            }

            $fileIndex = isset($file['file_index']) ? (int)$file['file_index'] : -1;
            $fileId = isset($file['file_id']) ? (string)$file['file_id'] : null;

            if ($fileIndex < 0 || $fileId === null || !isset($rowsByIndex[$fileIndex])) {
                continue;
            }

            $checksum = $rowsByIndex[$fileIndex]['checksum'];
            if ($checksum === '') {
                continue;
            }

            $toSubmit[] = [
                'file_id' => $fileId,
                'sha256' => $checksum,
            ];

            if (count($toSubmit) >= $this->hashBatchSize) {
                $client->submitHashes($this->imunifyJobId, $toSubmit);
                $totalSubmitted += count($toSubmit);
                $toSubmit = [];
            }
        }

        // Flush the trailing partial batch — Imunify accepts < hashBatchSize.
        if (!empty($toSubmit)) {
            $client->submitHashes($this->imunifyJobId, $toSubmit);
            $totalSubmitted += count($toSubmit);
        }

        if ($totalSubmitted > 0) {
            Log::debug('PollImunifyJob: Hashes submitted', [
                'mrid' => $this->mrid,
                'imunify_job_id' => $this->imunifyJobId,
                'count' => $totalSubmitted,
            ]);
        }
    }

    /**
     * Upload files Imunify flagged as `needs_upload` until the per-tick time
     * budget (`$maxTime`) is exhausted, grouping them into `/upload-batch`
     * requests so N files cost one HTTP round trip instead of N.
     *
     * Batches are bounded by BOTH file count and total bytes. The byte budget is
     * what actually matters: files are only capped at `ScanPart::$maxFileSize`
     * (5 MB, and API-configurable), so a count-only limit would allow a ~50 MB
     * multipart — past the client's 30s HTTP timeout on a slow uplink, past the
     * tick budget, and straight into a 413 that costs the whole payload twice on
     * the fallback. Typical scannable WordPress files are a few KB, so real
     * batches fill on count and the byte cap only bites on the rare fat file.
     *
     * Reference (scanner.php) caps at 1/tick because it runs from AJAX (PHP
     * timeout pressure on the frontend); ours runs in a queue worker.
     *
     * @param ImunifyClient $client
     * @param array $files
     * @param array<int, array> $rowsByIndex
     * @param float $startTime From `getCurrentTime()` at the top of `handle()`.
     * @return void
     */
    private function processNeedsUpload(ImunifyClient $client, array $files, array $rowsByIndex, $startTime)
    {
        $uploaded = 0;
        $batch = [];
        $batchBytes = 0;

        foreach ($files as $file) {
            if ($this->isTimeExceeded($startTime, $this->maxTime)) {
                break;
            }

            if (!isset($file['action']) || $file['action'] !== 'needs_upload') {
                continue;
            }

            $fileIndex = isset($file['file_index']) ? (int)$file['file_index'] : -1;
            $fileId = isset($file['file_id']) ? (string)$file['file_id'] : null;

            if ($fileIndex < 0 || $fileId === null || !isset($rowsByIndex[$fileIndex])) {
                continue;
            }

            $manifestPath = $rowsByIndex[$fileIndex]['path'];

            try {
                $absolute = ConsolidatedManifest::resolveSafePath($manifestPath);
            } catch (\Throwable $e) {
                Log::warning('PollImunifyJob: Path validation failed', [
                    'mrid' => $this->mrid,
                    'path' => $manifestPath,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if (!file_exists($absolute) || !is_readable($absolute)) {
                Log::warning('PollImunifyJob: File missing for upload', [
                    'mrid' => $this->mrid,
                    'path' => $absolute,
                ]);

                continue;
            }

            // Size on disk now, not the manifest's: the file may have grown
            // since it was enumerated and the budget has to reflect what we
            // are actually about to put on the wire.
            $size = filesize($absolute);
            $size = $size === false ? 0 : (int)$size;

            // A file that alone fills the budget never shares a request.
            if ($size >= self::UPLOAD_BATCH_MAX_BYTES) {
                $uploaded += $this->uploadSingle($client, $fileId, $absolute) ? 1 : 0;

                continue;
            }

            if (count($batch) >= self::UPLOAD_BATCH_MAX_FILES
                || ($batchBytes + $size) > self::UPLOAD_BATCH_MAX_BYTES) {
                $uploaded += $this->flushUploadBatch($client, $batch);
                $batch = [];
                $batchBytes = 0;
            }

            $batch[] = ['file_id' => $fileId, 'path' => $absolute];
            $batchBytes += $size;
        }

        $uploaded += $this->flushUploadBatch($client, $batch);

        if ($uploaded > 0) {
            Log::debug('PollImunifyJob: Files uploaded', [
                'mrid' => $this->mrid,
                'imunify_job_id' => $this->imunifyJobId,
                'count' => $uploaded,
            ]);
        }
    }

    /**
     * Send one accumulated batch, falling back to per-file uploads if the batch
     * endpoint refuses it — the caps are unpublished, so a rejection (typically
     * 413) is a documented, expected outcome rather than an error. The fallback
     * re-sends those bytes, which is exactly why the batch budget is set well
     * below anything likely to be refused.
     *
     * @param ImunifyClient $client
     * @param array $batch [{file_id, path}]
     * @return int Files accepted by Imunify.
     */
    private function flushUploadBatch(ImunifyClient $client, array $batch)
    {
        if (empty($batch)) {
            return 0;
        }

        // A lone file does not need the batch envelope.
        if (count($batch) === 1) {
            return $this->uploadSingle($client, $batch[0]['file_id'], $batch[0]['path']) ? 1 : 0;
        }

        try {
            $client->uploadFilesBatch($this->imunifyJobId, $batch);

            return count($batch);
        } catch (\Throwable $e) {
            Log::warning('PollImunifyJob: Batch upload rejected, falling back to per-file', [
                'mrid' => $this->mrid,
                'imunify_job_id' => $this->imunifyJobId,
                'batch_size' => count($batch),
                'error' => $e->getMessage(),
            ]);
        }

        $uploaded = 0;

        foreach ($batch as $item) {
            $uploaded += $this->uploadSingle($client, $item['file_id'], $item['path']) ? 1 : 0;
        }

        return $uploaded;
    }

    /**
     * Single-file `/upload`. A failure is not fatal: the file stays
     * `needs_upload` in Imunify's view and the next poll tick retries it.
     *
     * @param ImunifyClient $client
     * @param string $fileId
     * @param string $absolute
     * @return bool
     */
    private function uploadSingle(ImunifyClient $client, $fileId, $absolute)
    {
        try {
            $client->uploadFile($this->imunifyJobId, $absolute, $fileId);

            return true;
        } catch (\Throwable $e) {
            Log::warning('PollImunifyJob: Upload failed', [
                'mrid' => $this->mrid,
                'file_id' => $fileId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Build the verdict summary, stream-write the per-file NDJSON to the `scans`
     * disk, and dispatch `ProcessScanResultsUploadJob` to upload it to API
     * storage. The terminal `ManagerScanCompleted` is emitted by the upload job
     * once API has received the file — keeping "scan complete" and "results
     * available" in the same atomic signal from API's perspective.
     *
     * The NDJSON carries only **findings** (non-clean verdicts): the webhook
     * stays small (just counters in `summary`) and API derives the clean count
     * from `summary.clean`. Memory is bounded by the size of `rowsByIndex`
     * already used by the polling loop.
     *
     * @param array $files
     * @param string $providerStatus Verbatim Imunify status (`completed` on a
     *                               normal end, `failed` on a salvaged partial).
     *                               Propagated unchanged to the webhook so API
     *                               can branch on it.
     * @return void
     */
    private function emitDone(array $files, $providerStatus)
    {
        $summary = ['clean' => 0, 'malicious' => 0, 'suspicious' => 0, 'infected' => 0];

        foreach ($files as $file) {
            $verdict = isset($file['verdict']) ? $file['verdict'] : '';
            if (isset($summary[$verdict])) {
                $summary[$verdict]++;
            }
        }

        $resultsPath = ConsolidatedManifest::resultsPathFor($this->name);
        $written = $this->writeResultsNdjson($files, $resultsPath);

        if (!$written) {
            Log::error('PollImunifyJob: Failed to write scan results NDJSON', [
                'mrid' => $this->mrid,
                'results_path' => $resultsPath,
            ]);

            Scan::cancelFiles(
                $this->mrid,
                $this->name,
                $this->siteScan,
                'results_write_failed'
            );

            return;
        }

        dispatch(new ProcessScanResultsUploadJob(
            $this->mrid,
            $this->name,
            $this->siteScan,
            $resultsPath,
            $this->totalItems,
            $summary,
            (string)$providerStatus
        ));
    }

    /**
     * Cheap predicate for the salvage path: any file carrying a non-empty
     * `verdict` means Imunify analyzed it before the job died, so we have
     * actionable findings to forward. Files still in `needs_sha256` or
     * `needs_upload` have no `verdict` field and don't count.
     */
    private function hasAnyVerdict(array $files): bool
    {
        foreach ($files as $file) {
            if (isset($file['verdict']) && $file['verdict'] !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * Stream-write the per-finding NDJSON. One JSON line per non-clean file,
     * joining Imunify's verdict with the manifest row (`path`, `checksum`,
     * `size`) by `file_index`. fopen/fwrite — never buffers more than one
     * line in memory beyond `rowsByIndex` itself.
     *
     * `signature_name` and `threat_type` are the only detection detail the
     * public Connect API exposes (the panel's human-readable `verdict_details`
     * is not part of `/results`), so they ride along verbatim — without them a
     * finding reaches the app as "suspicious file X" with nothing to act on.
     * Both are nullable: Imunify omits them for verdicts it cannot attribute
     * to a signature, and the API maps them with `data_get()`.
     *
     * @param array $files Imunify's final `files[]` (every entry has `verdict`).
     * @param string $resultsPath Target path on the `scans` disk.
     * @return bool
     */
    private function writeResultsNdjson(array $files, $resultsPath)
    {
        $rowsByIndex = ConsolidatedManifest::rowsByIndex($this->manifestPath);
        $absolute = Storage::disk('scans')->path($resultsPath);
        $handle = fopen($absolute, 'w');

        if (!$handle) {
            return false;
        }

        try {
            foreach ($files as $file) {
                $fileIndex = isset($file['file_index']) ? (int)$file['file_index'] : -1;
                $verdict = isset($file['verdict']) ? (string)$file['verdict'] : '';

                // Skip clean and missing-verdict entries: the NDJSON is the
                // findings audit, not the full inventory. API can derive
                // `clean = total_files - lines(NDJSON)` from `summary.clean`.
                if ($verdict === '' || $verdict === 'clean') {
                    continue;
                }

                if ($fileIndex < 0 || !isset($rowsByIndex[$fileIndex])) {
                    continue;
                }

                $row = $rowsByIndex[$fileIndex];

                fwrite($handle, json_encode([
                        'file_index' => $fileIndex,
                        'path' => $row['path'],
                        'checksum' => $row['checksum'],
                        'size' => $row['size'],
                        'verdict' => $verdict,
                        'signature_name' => $this->detailField($file, 'signature_name'),
                        'threat_type' => $this->detailField($file, 'threat_type'),
                    ]) . "\n");
            }
        } finally {
            fclose($handle);
        }

        return true;
    }

    /**
     * Read an optional detection-detail field from an Imunify `files[]` entry,
     * collapsing "absent" and "empty string" into a single `null` so the API
     * does not have to distinguish them when importing the finding.
     *
     * @param array $file
     * @param string $key
     * @return string|null
     */
    private function detailField(array $file, $key)
    {
        if (!isset($file[$key]) || !is_scalar($file[$key])) {
            return null;
        }

        $value = trim((string)$file[$key]);

        return $value === '' ? null : $value;
    }

    /**
     * Called by the queue worker after the final retry has failed. Emits
     * `ManagerScanCancelled::forFiles` via `Scan::cancelFiles()` so API gets a
     * terminal signal and the per-flow cache is wiped by ScanCleanupListener.
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
            $e ? $e->getMessage() : 'poll_failed'
        );
    }

    /**
     * Lock key per `site_scan` — two parallel scans on different sites are
     * independent, two ticks on the same scan are not.
     *
     * @return string
     */
    public function uniqueId()
    {
        return (string)$this->siteScan;
    }
}
