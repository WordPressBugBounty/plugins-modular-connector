<?php

namespace Modular\Connector\Security\Imunify\Jobs;

use Modular\Connector\Backups\Iron\Helpers\HasMaxTime;
use Modular\Connector\Security\Facades\Scan;
use Modular\Connector\Security\ScanManager;
use Modular\Connector\Security\Imunify\Events\ManagerScanPartUpdated;
use Modular\Connector\Security\Imunify\Events\ManagerScanProgressUpdated;
use Modular\Connector\Security\Imunify\Exceptions\ImunifyUnavailableException;
use Modular\Connector\Security\Imunify\Helpers\ConsolidatedManifest;
use Modular\Connector\Security\Imunify\ImunifyClient;
use Modular\ConnectorDependencies\Carbon\Carbon;
use Modular\ConnectorDependencies\GuzzleHttp\Exception\ClientException;
use Modular\ConnectorDependencies\GuzzleHttp\Exception\GuzzleException;
use Modular\ConnectorDependencies\Illuminate\Bus\Queueable;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldQueue;
use Modular\ConnectorDependencies\Illuminate\Foundation\Bus\Dispatchable;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Cache;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Storage;
use function Modular\ConnectorDependencies\dispatch;
use function Modular\ConnectorDependencies\event;

/**
 * Drives the Imunify scan by polling, replacing the webhook-driven design.
 *
 * Pattern (mirrors the reference plugin's scanner.php::process_scan):
 *   1. GET /jobs/{id}/results — returns the pending file rows with their `action`.
 *   2. Iterate `files`:
 *        - `action=needs_sha256` → batched POSTs to /jobs/{id}/hashes (100 per batch,
 *          as many batches as the tick budget allows)
 *        - `action=needs_upload` → grouped multipart POSTs to
 *          /jobs/{id}/upload-batch (10 files or 5 MB per request), with a
 *          per-file /jobs/{id}/upload fallback when the batch is refused
 *   3. If `is_complete` → emit `ManagerScanCompleted` and stop.
 *   4. Else → emit `ManagerScanProgressUpdated::inProgress` (throttled) and re-dispatch.
 *
 * Imunify's response IS the work queue: files needing action carry the action tag,
 * processed files don't. The plugin never tracks "what I've already submitted"
 * locally — it trusts Imunify's view of state on every poll.
 *
 * `files[]` only holds the surviving rows: the backend keeps no row for a clean
 * file, so counts never come from that list. `total_files`, `completed_files`
 * and `summary` are the only reliable numbers.
 *
 * A 429/503 from Imunify (load shedding, maintenance) is not a failure: the job
 * marks `busyUntil = now + Retry-After`, keeps every cursor and re-dispatches
 * itself in slices of HEARTBEAT_INTERVAL_SECONDS. Each slice emits a throttled
 * `manager.scan.progress.updated` with the last known stats and re-queues
 * without touching Imunify until `busyUntil` has passed, so API keeps seeing
 * envelopes during a long wait and its stall heuristic stays quiet.
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
     * @var int Seconds between attempts after an uncaught exception. Without
     *          it the worker retries a released job immediately and the three
     *          attempts burn in seconds against a still-broken transport.
     */
    public $backoff = 60;

    /**
     * @var int TTL (seconds) for the unique lock. Long enough to span the
     *          typical inter-tick window (wp-cron firing) and the longest
     *          busy re-dispatch delay (ImunifyUnavailableException caps at
     *          540 s); short enough that if the chain dies, the kick endpoint
     *          can revive it after this window.
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
     * @var int Total files in the consolidated manifest. Fallback for
     *          `total_files` when Imunify does not report it.
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
     *          flipping `is_complete`. Computed once by `Scan::pollDeadlineAt()`
     *          from the `max_poll_hours` site request field (24 h fallback).
     *          `0` means "no deadline" — kept for forward-compat, no caller
     *          produces 0 today.
     */
    protected $pollDeadlineAt;

    /**
     * @var int Unix timestamp before which Imunify must not be called again
     *          (its Retry-After). 0 when not waiting. Ticks that wake up before
     *          it only emit a heartbeat and re-queue.
     */
    protected $busyUntil;

    /**
     * @var array Stats of the last real poll ({total_files, needs_sha256,
     *            needs_upload, has_verdict}) so heartbeats carry a truthful
     *            progress instead of zeros. Empty until the first poll.
     */
    protected $lastStats;

    /**
     * @var array<string, int> file_id => failed upload attempts. Loaded from
     *                         cache at the start of the upload phase, persisted
     *                         back when it ends (or is interrupted).
     */
    private $uploadFailures = [];

    /**
     * @var bool
     */
    private $uploadFailuresDirty = false;

    /**
     * @var int Matches the reference SDK (100 per `/hashes` call).
     */
    private $hashBatchSize = 100;

    /**
     * @var int Files per `/upload-batch` request.
     */
    private const UPLOAD_BATCH_MAX_FILES = 10;

    /**
     * @var int Byte budget per `/upload-batch` request. Independent of
     *          `ScanPart::$excludedSize` (which API can raise), so a bigger
     *          per-file cap shrinks the batch instead of inflating the request.
     *          The backend caps the body at 8 MiB.
     */
    private const UPLOAD_BATCH_MAX_BYTES = 5242880;

    /**
     * @var int A file whose upload fails this many times (rejected by Imunify,
     *          unreadable, transport error) is left alone for the rest of the
     *          scan instead of being retried every tick until the poll deadline.
     *          Busy answers (429/503) never count. Matches the reference plugin.
     */
    private const UPLOAD_MAX_ATTEMPTS = 3;

    /**
     * @var int Minimum seconds between two consecutive progress webhooks, and
     *          the slice length of a busy wait. Shared with the other flows
     *          through ScanManager so API sees one cadence.
     */
    private const PROGRESS_WEBHOOK_THROTTLE_SECONDS = ScanManager::HEARTBEAT_INTERVAL_SECONDS;

    /**
     * @var string[] Per-file spellings of "nobody judged this file". The backend
     *               normalises both to `unknown` today and names the split in
     *               `verdict_reason`; the SDK accepts both, so do we.
     */
    private const UNKNOWN_VERDICTS = ['unknown', 'not_scanned'];

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
     * @param int $busyUntil Unix timestamp of an Imunify Retry-After still in force; 0 when none.
     * @param array $lastStats Stats of the last real poll, for heartbeats.
     */
    public function __construct(
        $mrid,
        $name,
        $siteScan,
        $imunifyJobId,
        $manifestPath,
        $totalItems,
        $lastWebhookAt = 0,
        $pollDeadlineAt = 0,
        $busyUntil = 0,
        array $lastStats = []
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
        $this->busyUntil = (int)$busyUntil;
        $this->lastStats = $lastStats;
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

        // Still inside an Imunify Retry-After: heartbeat and come back later
        // without calling the provider. Slices are at most one heartbeat long.
        if ($this->busyUntil > 0) {
            $now = Carbon::now()->timestamp;

            if ($now < $this->busyUntil) {
                $this->heartbeat($now);

                dispatch($this->nextTick($this->busyUntil))
                    ->delay(min($this->busyUntil - $now, self::PROGRESS_WEBHOOK_THROTTLE_SECONDS));

                return;
            }

            $this->busyUntil = 0;
        }

        $startTime = $this->getCurrentTime();

        try {
            $client = ImunifyClient::make();

            $results = $client->getJobResults($this->imunifyJobId);

            $files = isset($results['files']) && is_array($results['files']) ? $results['files'] : [];
            $providerStatus = isset($results['status']) ? (string)$results['status'] : '';

            // Imunify can mark the job as terminally failed before is_complete
            // flips (repeated stalls with no progress). If the response still
            // carries verdicts in `files[]`, salvage them: emit
            // ManagerScanCompleted with `status=failed` so API gets the
            // findings instead of a Cancelled event that throws them away.
            // Only fall back to the cancel path when there is no verdict at
            // all. Whether the current backend populates `files[]` on a failed
            // job is unconfirmed — the salvage path is harmless either way.
            if ($providerStatus === 'failed') {
                Log::error('PollImunifyJob: Imunify reports job failed', [
                    'mrid' => $this->mrid,
                    'imunify_job_id' => $this->imunifyJobId,
                    'completed_files' => isset($results['completed_files']) ? (int)$results['completed_files'] : null,
                    'total_files' => isset($results['total_files']) ? (int)$results['total_files'] : null,
                    'summary' => isset($results['summary']) ? $results['summary'] : null,
                    'files' => count($files),
                    'error' => isset($results['error']) ? (string)$results['error'] : null,
                ]);

                if ($this->hasAnyVerdict($files)) {
                    $this->emitDone($results, $providerStatus);
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
                $this->emitDone($results, $providerStatus);

                return;
            }

            $rowsByIndex = ConsolidatedManifest::rowsByIndex($this->manifestPath);

            // Order matters: hashes first (cheap batch op, unblocks many files at
            // once) then uploads (one multipart POST per batch). Both share the
            // same time budget; if hashes consume it all, uploads pick up next
            // tick — that's the correct priority anyway.
            $this->processNeedsSha256($client, $files, $rowsByIndex, $startTime);
            $this->processNeedsUpload($client, $files, $rowsByIndex, $startTime);
        } catch (ImunifyUnavailableException $e) {
            // Load shedding / maintenance / rate limit: nothing was committed.
            // Keep the job and every cursor, come back after Retry-After.
            $this->redispatchLater($e);

            return;
        } catch (\Throwable $e) {
            Log::error('PollImunifyJob: tick failed', [
                'mrid' => $this->mrid,
                'imunify_job_id' => $this->imunifyJobId,
                'error' => $e->getMessage(),
            ]);

            // Let the queue retry ($tries=3, $backoff). On final failure the
            // failed() handler emits the terminal failure event and cleans up.
            throw $e;
        }

        // Emit progress on the first poll (start) and afterwards only every
        // PROGRESS_WEBHOOK_THROTTLE_SECONDS — avoids spamming API on every tick.
        // The stats are kept either way so a later heartbeat reports them.
        $this->lastStats = $this->buildStats($results, $files);
        $this->heartbeat(Carbon::now()->timestamp);

        // Re-dispatch for the next poll tick, carrying the updated webhook cursor
        // and the original deadline so the cap survives across runs.
        dispatch($this->nextTick());
    }

    /**
     * Same chain state, ready to be dispatched again.
     *
     * @param int $busyUntil
     * @return self
     */
    private function nextTick($busyUntil = 0)
    {
        return new self(
            $this->mrid,
            $this->name,
            $this->siteScan,
            $this->imunifyJobId,
            $this->manifestPath,
            $this->totalItems,
            $this->lastWebhookAt,
            $this->pollDeadlineAt,
            $busyUntil,
            $this->lastStats
        );
    }

    /**
     * Imunify asked us to back off. Remember until when, heartbeat, and
     * re-dispatch the same tick in slices no longer than one heartbeat so API
     * keeps receiving envelopes — the poll deadline still bounds the chain.
     *
     * @param ImunifyUnavailableException $e
     * @return void
     */
    private function redispatchLater(ImunifyUnavailableException $e)
    {
        $now = Carbon::now()->timestamp;
        $busyUntil = $now + $e->delaySeconds();

        Log::warning('PollImunifyJob: Imunify busy, retrying later', array_merge([
            'mrid' => $this->mrid,
            'imunify_job_id' => $this->imunifyJobId,
            'busy_until' => $busyUntil,
        ], $e->context()));

        $this->heartbeat($now);

        dispatch($this->nextTick($busyUntil))
            ->delay(min($e->delaySeconds(), self::PROGRESS_WEBHOOK_THROTTLE_SECONDS));
    }

    /**
     * Emit `manager.scan.progress.updated` with the last known stats when the
     * throttle window has elapsed. Used both after a real poll and while
     * waiting out a Retry-After, so API's "no envelope for N minutes" stall
     * heuristic never fires on a legitimate wait.
     *
     * @param int $now Unix timestamp.
     * @return void
     */
    private function heartbeat($now)
    {
        if (!$this->shouldEmitProgress($now)) {
            return;
        }

        // Never invent stats: API persists them as the scan's progress, and a
        // heartbeat with zeros after real progress would make the bar go
        // backwards. A chain that has not polled yet (first tick after
        // SubmitToImunifyJob, or one revived by the kick endpoint) stays
        // silent until its first real poll — the `started` envelope or the
        // kick itself just refreshed the step, and a Retry-After is capped at
        // 9 minutes, under API's 10-minute stall threshold.
        if (empty($this->lastStats)) {
            Log::debug('PollImunifyJob: heartbeat skipped, no stats from a real poll yet', [
                'mrid' => $this->mrid,
                'imunify_job_id' => $this->imunifyJobId,
            ]);

            return;
        }

        event(ManagerScanProgressUpdated::inProgress(
            $this->mrid,
            $this->siteScan,
            ManagerScanPartUpdated::TYPE_FILES,
            $this->lastStats['total_files'],
            $this->lastStats['needs_sha256'],
            $this->lastStats['needs_upload'],
            $this->lastStats['has_verdict']
        ));

        $this->lastWebhookAt = $now;
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
     * Total files the scan covers: the total Imunify froze for the job when it
     * reports one, else our manifest count.
     *
     * @param array $results
     * @return int
     */
    private function totalFilesFrom(array $results)
    {
        if (isset($results['total_files']) && (int)$results['total_files'] > 0) {
            return (int)$results['total_files'];
        }

        return $this->totalItems;
    }

    /**
     * Build the in-flight counts for `manager.scan.progress.updated`.
     *
     * Pending work is counted from `files[]` (those rows survive until resolved).
     * Verdicted files come from `completed_files`: clean rows are pruned from
     * `files[]`, so counting `has_verdict` there would freeze progress near 30 %.
     *
     * @param array $results
     * @param array $files
     * @return array {total_files, needs_sha256, needs_upload, has_verdict}
     */
    private function buildStats(array $results, array $files)
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

        if (isset($results['completed_files'])) {
            $hasVerdict = (int)$results['completed_files'];
        }

        return [
            'total_files' => $this->totalFilesFrom($results),
            'needs_sha256' => $needsSha256,
            'needs_upload' => $needsUpload,
            'has_verdict' => $hasVerdict,
        ];
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
     * what actually matters: files are only capped at `ScanPart::$excludedSize`
     * (5 MB, and API-configurable), so a count-only limit would allow a ~50 MB
     * multipart — past the client's 30s HTTP timeout on a slow uplink, past the
     * tick budget, and straight into a 413 that costs the whole payload twice on
     * the fallback. Typical scannable WordPress files are a few KB, so real
     * batches fill on count and the byte cap only bites on the rare fat file.
     *
     * Files that already failed UPLOAD_MAX_ATTEMPTS times are skipped; the
     * counter lives in cache so it survives across ticks.
     *
     * @param ImunifyClient $client
     * @param array $files
     * @param array<int, array> $rowsByIndex
     * @param float $startTime From `getCurrentTime()` at the top of `handle()`.
     * @return void
     */
    private function processNeedsUpload(ImunifyClient $client, array $files, array $rowsByIndex, $startTime)
    {
        $this->loadUploadFailures();

        $uploaded = 0;
        $skipped = 0;
        $batch = [];
        $batchBytes = 0;

        try {
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

                if ($this->uploadAttempts($fileId) >= self::UPLOAD_MAX_ATTEMPTS) {
                    $skipped++;

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

                    $this->recordUploadFailure($fileId);

                    continue;
                }

                if (!file_exists($absolute) || !is_readable($absolute)) {
                    Log::warning('PollImunifyJob: File missing for upload', [
                        'mrid' => $this->mrid,
                        'path' => $absolute,
                    ]);

                    $this->recordUploadFailure($fileId);

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
        } finally {
            // Also runs when Imunify goes busy mid-phase, so attempts counted
            // before the interruption are not lost.
            $this->persistUploadFailures();
        }

        if ($uploaded > 0 || $skipped > 0) {
            Log::debug('PollImunifyJob: Files uploaded', [
                'mrid' => $this->mrid,
                'imunify_job_id' => $this->imunifyJobId,
                'count' => $uploaded,
                'skipped_after_max_attempts' => $skipped,
            ]);
        }
    }

    /**
     * Send one accumulated batch. What happens on failure depends on how it
     * failed, because a per-file re-upload is only safe when the backend is
     * known not to have persisted anything:
     *
     *   - 429/503 → nothing committed, propagate so the tick re-dispatches later.
     *   - 4xx (413 body too large, 404 on an older backend, ...) → the whole
     *     batch was refused before persisting anything → per-file fallback.
     *   - 5xx or transport failure → ambiguous, some files may already be
     *     accepted; re-uploading would duplicate their scan work. Leave them
     *     `needs_upload`, the next tick retries.
     *   - Local pre-flight error (unreadable file) → nothing reached the
     *     backend → per-file fallback so one bad file does not drop its
     *     healthy siblings.
     *
     * A 2xx/422 with `items[]` is authoritative per-item accounting: rejected
     * items count as a failed attempt for that file, nothing is re-sent.
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
            $response = $client->uploadFilesBatch($this->imunifyJobId, $batch);
        } catch (ImunifyUnavailableException $e) {
            throw $e;
        } catch (ClientException $e) {
            Log::warning('PollImunifyJob: Batch upload refused, falling back to per-file', [
                'mrid' => $this->mrid,
                'imunify_job_id' => $this->imunifyJobId,
                'batch_size' => count($batch),
                'status' => $e->getResponse() ? $e->getResponse()->getStatusCode() : null,
                'error' => $e->getMessage(),
            ]);

            return $this->uploadEach($client, $batch);
        } catch (GuzzleException $e) {
            Log::warning('PollImunifyJob: Batch upload failed ambiguously, not re-uploading per-file', [
                'mrid' => $this->mrid,
                'imunify_job_id' => $this->imunifyJobId,
                'batch_size' => count($batch),
                'error' => $e->getMessage(),
            ]);

            return 0;
        } catch (\Throwable $e) {
            Log::warning('PollImunifyJob: Batch upload pre-flight error, falling back to per-file', [
                'mrid' => $this->mrid,
                'imunify_job_id' => $this->imunifyJobId,
                'batch_size' => count($batch),
                'error' => $e->getMessage(),
            ]);

            return $this->uploadEach($client, $batch);
        }

        return $this->countAcceptedItems($response, $batch);
    }

    /**
     * @param ImunifyClient $client
     * @param array $batch
     * @return int
     */
    private function uploadEach(ImunifyClient $client, array $batch)
    {
        $uploaded = 0;

        foreach ($batch as $item) {
            $uploaded += $this->uploadSingle($client, $item['file_id'], $item['path']) ? 1 : 0;
        }

        return $uploaded;
    }

    /**
     * Read the per-item accounting of an `/upload-batch` response. Without an
     * `items[]` array (older backend) the whole batch is assumed accepted.
     *
     * @param array $response
     * @param array $batch
     * @return int
     */
    private function countAcceptedItems(array $response, array $batch)
    {
        if (!isset($response['items']) || !is_array($response['items'])) {
            return count($batch);
        }

        $accepted = 0;

        foreach ($response['items'] as $index => $item) {
            $status = isset($item['status']) ? (string)$item['status'] : 'accepted';

            if ($status === 'accepted') {
                $accepted++;

                continue;
            }

            $fileId = isset($batch[$index]['file_id']) ? $batch[$index]['file_id'] : null;

            if ($fileId !== null) {
                $this->recordUploadFailure($fileId);
            }

            Log::warning('PollImunifyJob: Upload rejected by Imunify', [
                'mrid' => $this->mrid,
                'file_id' => $fileId,
                'status' => $status,
                'error' => isset($item['error']) ? (string)$item['error'] : null,
            ]);
        }

        return $accepted;
    }

    /**
     * Single-file `/upload`. A failure is not fatal: the file stays
     * `needs_upload` in Imunify's view and the next poll tick retries it,
     * until UPLOAD_MAX_ATTEMPTS. A busy answer propagates (never counted).
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
        } catch (ImunifyUnavailableException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->recordUploadFailure($fileId);

            Log::warning('PollImunifyJob: Upload failed', [
                'mrid' => $this->mrid,
                'file_id' => $fileId,
                'attempts' => $this->uploadAttempts($fileId),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @return void
     */
    private function loadUploadFailures()
    {
        $stored = Cache::get(Scan::uploadFailuresKey($this->siteScan), []);

        $this->uploadFailures = is_array($stored) ? $stored : [];
        $this->uploadFailuresDirty = false;
    }

    /**
     * @return void
     */
    private function persistUploadFailures()
    {
        if (!$this->uploadFailuresDirty) {
            return;
        }

        Cache::put(Scan::uploadFailuresKey($this->siteScan), $this->uploadFailures, Carbon::now()->addDay());
        $this->uploadFailuresDirty = false;
    }

    /**
     * @param string $fileId
     * @return int
     */
    private function uploadAttempts($fileId)
    {
        return isset($this->uploadFailures[$fileId]) ? (int)$this->uploadFailures[$fileId] : 0;
    }

    /**
     * @param string $fileId
     * @return void
     */
    private function recordUploadFailure($fileId)
    {
        $this->uploadFailures[$fileId] = $this->uploadAttempts($fileId) + 1;
        $this->uploadFailuresDirty = true;
    }

    /**
     * Build the verdict summary, stream-write the per-file NDJSON to the `scans`
     * disk, and dispatch `ProcessScanResultsUploadJob` to upload it to API
     * storage. The terminal `ManagerScanCompleted` is emitted by the upload job
     * once API has received the file — keeping "scan complete" and "results
     * available" in the same atomic signal from API's perspective.
     *
     * The NDJSON carries only **findings** (malicious / suspicious): the webhook
     * stays small (just counters in `summary`) and API derives the clean and
     * unknown counts from `summary`. Memory is bounded by the size of
     * `rowsByIndex` already used by the polling loop.
     *
     * @param array $results Full `/results` body.
     * @param string $providerStatus Verbatim Imunify status (`completed` on a
     *                               normal end, `failed` on a salvaged partial).
     *                               Propagated unchanged to the webhook so API
     *                               can branch on it.
     * @return void
     */
    private function emitDone(array $results, $providerStatus)
    {
        $files = isset($results['files']) && is_array($results['files']) ? $results['files'] : [];
        $summary = $this->buildSummary($results, $files);

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
            $this->totalFilesFrom($results),
            $summary,
            (string)$providerStatus
        ));
    }

    /**
     * Per-verdict counters for the `manager.scan.completed` webhook.
     *
     * Imunify's `summary` is the source of truth: `files[]` no longer holds
     * clean rows, so counting verdicts there would report almost no clean
     * files. Walking `files[]` is only the fallback for a backend that sends
     * no summary at all. `infected` is kept at 0 for API compatibility — it is
     * a cleanup category, never a verdict.
     *
     * @param array $results
     * @param array $files
     * @return array {clean, malicious, suspicious, unknown, infected}
     */
    private function buildSummary(array $results, array $files)
    {
        $summary = ['clean' => 0, 'malicious' => 0, 'suspicious' => 0, 'unknown' => 0, 'infected' => 0];

        if (isset($results['summary']) && is_array($results['summary'])) {
            foreach (['clean', 'malicious', 'suspicious', 'unknown'] as $bucket) {
                $summary[$bucket] = isset($results['summary'][$bucket]) ? (int)$results['summary'][$bucket] : 0;
            }

            return $summary;
        }

        foreach ($files as $file) {
            $verdict = isset($file['verdict']) ? (string)$file['verdict'] : '';

            if (in_array($verdict, self::UNKNOWN_VERDICTS, true)) {
                $summary['unknown']++;
            } elseif (isset($summary[$verdict])) {
                $summary[$verdict]++;
            }
        }

        return $summary;
    }

    /**
     * Cheap predicate for the salvage path: any file carrying a real verdict
     * means Imunify analyzed it before the job died, so we have actionable
     * findings to forward. Files still pending have no `verdict` field, and
     * `unknown` / `not_scanned` are "nobody judged this file" — neither counts.
     *
     * @param array $files
     * @return bool
     */
    private function hasAnyVerdict(array $files)
    {
        foreach ($files as $file) {
            if ($this->isFinding($file)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True for a row whose verdict is something the API can act on
     * (`malicious`, `suspicious`, or any future non-clean verdict). Clean and
     * unknown rows are counters, not findings.
     *
     * @param array $file
     * @return bool
     */
    private function isFinding(array $file)
    {
        $verdict = isset($file['verdict']) ? trim((string)$file['verdict']) : '';

        return $verdict !== '' && $verdict !== 'clean' && !in_array($verdict, self::UNKNOWN_VERDICTS, true);
    }

    /**
     * Stream-write the per-finding NDJSON. One JSON line per non-clean,
     * non-unknown file, joining Imunify's verdict with the manifest row
     * (`path`, `checksum`, `size`) by `file_index`. fopen/fwrite — never
     * buffers more than one line in memory beyond `rowsByIndex` itself.
     *
     * `signature_name` and `threat_type` are the only detection detail the
     * public Connect API exposes (the panel's human-readable `verdict_details`
     * is not part of `/results`), so they ride along verbatim — without them a
     * finding reaches the app as "suspicious file X" with nothing to act on.
     * Both are nullable: Imunify omits them for verdicts it cannot attribute
     * to a signature, and the API maps them with `data_get()`.
     *
     * @param array $files Imunify's final `files[]`.
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
                if (!$this->isFinding($file)) {
                    continue;
                }

                $fileIndex = isset($file['file_index']) ? (int)$file['file_index'] : -1;

                if ($fileIndex < 0 || !isset($rowsByIndex[$fileIndex])) {
                    continue;
                }

                $row = $rowsByIndex[$fileIndex];

                fwrite($handle, json_encode([
                        'file_index' => $fileIndex,
                        'path' => $row['path'],
                        'checksum' => $row['checksum'],
                        'size' => $row['size'],
                        'verdict' => trim((string)$file['verdict']),
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
