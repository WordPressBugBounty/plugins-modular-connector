<?php

namespace Modular\Connector\Security\Imunify\Jobs;

use Modular\Connector\Backups\Iron\Helpers\HasMaxTime;
use Modular\Connector\Security\Facades\Scan;
use Modular\Connector\Security\Imunify\Events\ManagerScanFilesMergeUpdated;
use Modular\Connector\Security\Imunify\Events\ManagerScanPartUpdated;
use Modular\Connector\Security\Imunify\Helpers\ConsolidatedManifest;
use Modular\ConnectorDependencies\Illuminate\Bus\Queueable;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldQueue;
use Modular\ConnectorDependencies\Illuminate\Foundation\Bus\Dispatchable;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Cache;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Storage;
use function Modular\ConnectorDependencies\dispatch;
use function Modular\ConnectorDependencies\event;

class MergeScanManifestsJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;
    use HasMaxTime;

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
     * @var array
     */
    protected $partsStatus;

    /**
     * @var int Sequential file_index across all merged disks. Carries over
     *          when the job re-dispatches itself mid-merge.
     */
    protected $fileIndex;

    /**
     * @var array Disk types already fully drained into the consolidated manifest.
     */
    protected $processedTypes;

    /**
     * @var string|null Type of the disk currently in flight (non-null only after a
     *                  mid-disk time-exceed re-dispatch).
     */
    protected $currentType;

    /**
     * @var int Byte offset into `currentType`'s per-disk manifest where the
     *          previous run left off (0 unless mid-disk).
     */
    protected $currentOffset;

    /**
     * @var int Aligned with ScanManifest::calculate(): leaves enough margin on
     *          shared hosting (≈1MB/s I/O) to fflush, persist cursor and
     *          re-dispatch self before PHP's max_execution_time kicks in.
     */
    protected $maxTime = 25;

    /**
     * Cursor state (`fileIndex`, `processedTypes`, `currentType`, `currentOffset`)
     * travels in the constructor — same pattern as `CalculateManifestJob` (carries
     * progress via `$part->offset`) and `ScanDbJob` (carries `$tableIndex` etc.).
     *
     * Defaults represent a fresh-start run; re-dispatches from `merge()` provide
     * the latest position so the next run resumes from there.
     *
     * @param string $mrid
     * @param string $name
     * @param int $siteScan
     * @param array $partsStatus
     * @param int $fileIndex
     * @param array $processedTypes
     * @param string|null $currentType
     * @param int $currentOffset
     */
    public function __construct(
        $mrid,
        $name,
        $siteScan,
        array $partsStatus,
        $fileIndex = 0,
        array $processedTypes = [],
        $currentType = null,
        $currentOffset = 0
    )
    {
        $this->mrid = $mrid;
        $this->name = $name;
        $this->siteScan = $siteScan;
        $this->partsStatus = $partsStatus;
        $this->fileIndex = (int)$fileIndex;
        $this->processedTypes = $processedTypes;
        $this->currentType = $currentType;
        $this->currentOffset = (int)$currentOffset;
        $this->queue = 'backups';
    }

    /**
     * @return bool True when the constructor args carry no progress — i.e. this
     *              is the first invocation, not a re-dispatched resume.
     */
    private function isFreshStart()
    {
        return $this->fileIndex === 0
            && empty($this->processedTypes)
            && $this->currentType === null;
    }

    /**
     * @return void
     */
    public function handle()
    {
        if (Scan::isFilesCancelled($this->name)) {
            return;
        }

        $consolidatedPath = ConsolidatedManifest::pathFor($this->name);

        if ($this->isFreshStart()) {
            Log::debug('MergeScanManifestsJob: Starting merge', [
                'mrid' => $this->mrid,
                'parts' => array_keys($this->partsStatus),
                'consolidated_path' => $consolidatedPath,
            ]);

            // Emit "merge in_progress" only on the first run so resumes don't
            // generate redundant webhooks every time the job re-dispatches itself.
            event(ManagerScanFilesMergeUpdated::inProgress(
                $this->mrid,
                $this->siteScan
            ));
        }

        try {
            $totalItems = $this->merge($consolidatedPath);

            // Time exhausted mid-merge; merge() re-dispatched self with the updated cursor.
            if ($totalItems === null) {
                return;
            }

            Log::debug('MergeScanManifestsJob: Merge complete', [
                'mrid' => $this->mrid,
                'total_items' => $totalItems,
            ]);

            // Emit before the coordination-cache cleanup so a re-emit after a
            // cleanup error still has the cache around. The listener
            // (ScanFilesSubmitDispatcherListener) filters status=done and owns
            // the "merge → submit" pipeline transition.
            event(ManagerScanFilesMergeUpdated::done(
                $this->mrid,
                $this->name,
                $this->siteScan,
                $consolidatedPath,
                $totalItems
            ));

            try {
                $this->cleanupCoordinationCache();
            } catch (\Throwable $cleanupError) {
                Log::warning('MergeScanManifestsJob: Cleanup failed after emit', [
                    'mrid' => $this->mrid,
                    'error' => $cleanupError->getMessage(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('MergeScanManifestsJob: Merge failed', [
                'mrid' => $this->mrid,
                'error' => $e->getMessage(),
            ]);

            // Real failure (not a timed re-dispatch): wipe the partial consolidated
            // file so any retry — manual or by the queue — starts clean.
            if (Storage::disk('scans')->exists($consolidatedPath)) {
                Storage::disk('scans')->delete($consolidatedPath);
            }

            event(ManagerScanFilesMergeUpdated::failed(
                $this->mrid,
                $this->siteScan,
                $e->getMessage()
            ));

            throw $e;
        }
    }

    /**
     * Queue-worker callback once the job is marked failed (no `$tries`, so the
     * first uncaught exception is final). `handle()` already emitted
     * `merge failed`; this adds the terminal `cancelled` so API stops waiting
     * and ScanCleanupListener wipes the files-flow cache.
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
            $e ? 'merge_failed: ' . $e->getMessage() : 'merge_failed'
        );
    }

    /**
     * @return void
     */
    private function cleanupCoordinationCache()
    {
        Cache::forget(sprintf('_scan_parts_status_%s', $this->siteScan));
        Cache::forget(sprintf('_scan_parts_expected_%s', $this->siteScan));
    }

    /**
     * Concatenate all per-disk manifests into one, prefixing each path with the disk
     * type and assigning a sequential file_index. Each input row is converted to NDJSON
     * via `ConsolidatedManifest::rewriteCsvRow()`.
     *
     * Source format (per-disk, CSV): sha256;f;size;timestamp;"path"
     * Output format (consolidated, NDJSON): one JSON object per line.
     *
     * Resumable: cursor state (`fileIndex`, `processedTypes`, `currentType`,
     * `currentOffset`) comes in via the constructor — same pattern as
     * `CalculateManifestJob` and `ScanDbJob`. We open in append mode when resuming,
     * `fseek` the in-flight source to the saved byte offset, and on time exhaustion
     * we re-dispatch self with the updated cursor and return `null` so the caller
     * skips the success path.
     *
     * @param string $consolidatedPath
     * @return int|null Total items on completion, or null when the run had to re-dispatch.
     */
    private function merge($consolidatedPath)
    {
        $startTime = $this->getCurrentTime();
        $absoluteConsolidated = Storage::disk('scans')->path($consolidatedPath);

        $pending = $this->pendingTypes();

        $output = fopen($absoluteConsolidated, $this->isFreshStart() ? 'w' : 'a');

        if (!$output) {
            throw new \RuntimeException('Cannot open consolidated manifest for writing');
        }

        $fileIndex = $this->fileIndex;
        $processed = $this->processedTypes;

        try {
            foreach ($pending as $type) {
                $info = $this->partsStatus[$type];
                $sourcePath = Storage::disk('scans')->path($info['manifest_path']);
                $input = fopen($sourcePath, 'r');

                if (!$input) {
                    throw new \RuntimeException(sprintf('Cannot open source manifest: %s', $type));
                }

                try {
                    // Resume markers only apply to the first disk this run touches —
                    // by construction, that's the disk we were mid-merging on. Subsequent
                    // disks always start at byte 0 because currentType won't match them.
                    if ($this->currentType === $type && $this->currentOffset > 0) {
                        fseek($input, $this->currentOffset);

                        Log::debug('MergeScanManifestsJob: Resuming mid-disk', [
                            'mrid' => $this->mrid,
                            'type' => $type,
                            'offset' => $this->currentOffset,
                            'file_index' => $fileIndex,
                        ]);
                    }

                    while (($line = fgets($input)) !== false) {
                        $line = rtrim($line, "\r\n");

                        if ($line === '') {
                            continue;
                        }

                        $rewritten = ConsolidatedManifest::rewriteCsvRow($line, $type, $fileIndex);

                        if ($rewritten === null) {
                            Log::warning('MergeScanManifestsJob: Skipping malformed row', [
                                'mrid' => $this->mrid,
                                'type' => $type,
                            ]);

                            continue;
                        }

                        fwrite($output, $rewritten . "\n");
                        $fileIndex++;

                        if ($fileIndex % 500 === 0 && $this->isTimeExceeded($startTime, $this->maxTime)) {
                            fflush($output);
                            $offset = ftell($input);

                            Log::debug('MergeScanManifestsJob: Time exceeded mid-disk, re-dispatching', [
                                'mrid' => $this->mrid,
                                'type' => $type,
                                'offset' => $offset,
                                'file_index' => $fileIndex,
                            ]);

                            dispatch(new self(
                                $this->mrid,
                                $this->name,
                                $this->siteScan,
                                $this->partsStatus,
                                $fileIndex,
                                $processed,
                                $type,
                                $offset
                            ));

                            return null;
                        }
                    }
                } finally {
                    fclose($input);
                }

                $processed[] = $type;
                fflush($output);

                // Per-disk manifest is fully drained into the consolidated one (and
                // flushed to disk); free its space now instead of waiting until the
                // whole merge completes. On large sites the per-disk manifests can
                // weigh tens of MB each and the merge spans several ticks, so the
                // sooner we drop the drained ones, the lower the peak disk usage.
                if (Storage::disk('scans')->exists($info['manifest_path'])) {
                    Storage::disk('scans')->delete($info['manifest_path']);
                }

                if ($this->isTimeExceeded($startTime, $this->maxTime)) {
                    Log::debug('MergeScanManifestsJob: Time exceeded between disks, re-dispatching', [
                        'mrid' => $this->mrid,
                        'processed_count' => count($processed),
                        'file_index' => $fileIndex,
                    ]);

                    dispatch(new self(
                        $this->mrid,
                        $this->name,
                        $this->siteScan,
                        $this->partsStatus,
                        $fileIndex,
                        $processed,
                        null,
                        0
                    ));

                    return null;
                }
            }

            fflush($output);
        } finally {
            fclose($output);
        }

        return $fileIndex;
    }

    /**
     * Disks that produced a usable per-disk manifest and have not been merged yet
     * by a previous run of this job.
     *
     * Filtering rules (applied in order):
     *  - already in `processedTypes` → drained by a prior run, skip silently
     *  - `status !== STATUS_DONE` → EXCLUDED/FAILED parts contribute nothing
     *  - `manifest_path` empty or file missing on disk → log a warning and skip
     *
     * By precomputing this list once, the main merge loop becomes pure "do work" —
     * no skip-continue branches mixed with the byte-level streaming logic.
     *
     * @return array
     */
    private function pendingTypes()
    {
        $pending = [];

        foreach ($this->partsStatus as $type => $info) {
            if (in_array($type, $this->processedTypes, true)) {
                continue;
            }

            if ($info['status'] !== ManagerScanPartUpdated::STATUS_DONE) {
                continue;
            }

            if (empty($info['manifest_path']) || !Storage::disk('scans')->exists($info['manifest_path'])) {
                Log::warning('MergeScanManifestsJob: Manifest file missing', [
                    'type' => $type,
                    'manifest_path' => $info['manifest_path'] ?? null,
                ]);

                continue;
            }

            $pending[] = $type;
        }

        return $pending;
    }

}
