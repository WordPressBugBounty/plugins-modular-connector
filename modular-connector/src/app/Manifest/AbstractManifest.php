<?php

namespace Modular\Connector\Manifest;

use Modular\Connector\Backups\Iron\Helpers\File;
use Modular\Connector\Backups\Iron\Helpers\HasMaxTime;
use Modular\Connector\Backups\Iron\Helpers\ManifestHasher;
use Modular\Connector\Backups\Iron\Manifest\CalculateManifestJob;
use Modular\Connector\Manifest\Contracts\ManifestContract;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Http\HttpUtils;
use Modular\ConnectorDependencies\Illuminate\Support\Collection;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Storage;
use Modular\ConnectorDependencies\Illuminate\Support\Str;
use function Modular\ConnectorDependencies\dispatch;

/**
 * Per-disk CSV manifest writer driven by `CalculateManifestJob`, shared by
 * backups and scans.
 *
 * Walks the disk with the same finder and pagination for both flows (offset
 * over a `LimitIterator`, 25 s budget per run, adaptive write buffer, memory
 * checks), filters every file through `File::shouldExclude()`, and writes one
 * `checksum;type;size;timestamp;"path"` row per kept file. What differs per
 * flow goes through the hooks: which hasher (`createHasher()`), any extra
 * reason to skip a file (`shouldSkip()`), and how a row is built or dropped
 * (`mapItem()`). Status transitions come from the part.
 *
 * The final row count re-reads the manifest so the total reflects what is on
 * disk, not what a run believed it wrote.
 */
abstract class AbstractManifest implements ManifestContract
{
    use HasMaxTime;

    /**
     * @var AbstractManifestPart
     */
    protected AbstractManifestPart $part;

    /**
     * @var string
     */
    protected $delimiter = ';';

    /**
     * @var int
     */
    protected int $bufferSize = 100;

    /**
     * The maximum number of seconds a worker may live.
     *
     * @var int
     */
    protected int $maxTime = 25;

    /**
     * @param AbstractManifestPart $part
     */
    public function __construct(AbstractManifestPart $part)
    {
        $this->part = $part;

        // Adaptive buffer size based on memory limit
        $memoryLimit = HttpUtils::maxMemoryLimit(true);

        if ($memoryLimit === -1) {
            $this->bufferSize = 500; // Unlimited memory
        } elseif ($memoryLimit <= 128) {
            $this->bufferSize = 50;   // Very conservative
        } elseif ($memoryLimit <= 256) {
            $this->bufferSize = 100;  // Original value
        } elseif ($memoryLimit <= 512) {
            $this->bufferSize = 250;  // Moderate
        } else {
            $this->bufferSize = 500;  // More aggressive but safe
        }
    }

    /**
     * @param AbstractManifestPart $part
     * @return static
     */
    public static function create(AbstractManifestPart $part)
    {
        return new static($part);
    }

    /**
     * @return ManifestHasher
     */
    abstract protected function createHasher(): ManifestHasher;

    /**
     * Whether to leave a file out of the manifest. The shared rule is
     * `File::shouldExclude()` (readability, excluded extensions, size cap, the
     * mapped path exclusions); flows add their own on top.
     *
     * @param \SplFileInfo $file
     * @param Collection $excluded
     * @param Collection $excludedExtensions
     * @return bool
     */
    protected function shouldSkip(\SplFileInfo $file, Collection $excluded, Collection $excludedExtensions): bool
    {
        return File::shouldExclude($this->part->type, $file, $excluded, $excludedExtensions, $this->part->excludedSize);
    }

    /**
     * Build the manifest row for a file. Return null to drop the file.
     *
     * @param \SplFileInfo $file
     * @param ManifestHasher $hasher
     * @return array|null
     */
    protected function mapItem(\SplFileInfo $file, ManifestHasher $hasher): ?array
    {
        return File::mapItem($file, $this->part->type, false, true, $hasher);
    }

    /**
     * @param array $buffer
     * @param int $offset
     * @return void
     */
    private function writeManifest(array $buffer, int $offset): void
    {
        $currentOffset = $this->part->offset;
        $filePath = Storage::disk($this->part->getManifestDisk())->path($this->part->manifestPath);

        if ($currentOffset > 0 && !file_exists($filePath)) {
            throw new \RuntimeException('Manifest file not found');
        }

        // Open file for writing/appending
        $mode = $currentOffset === 0 ? 'w' : 'a';
        $handle = fopen($filePath, $mode);

        if (!$handle) {
            throw new \RuntimeException('Cannot open manifest file for writing');
        }

        try {
            // Write each line directly without creating large string
            foreach ($buffer as $line) {
                fwrite($handle, $line . "\n");
            }

            // Force write to disk
            fflush($handle);
        } finally {
            fclose($handle);
        }

        $this->part->offset = $offset;
    }

    /**
     * Rows in the manifest on disk.
     *
     * @return int
     */
    public function count(): int
    {
        $disk = $this->part->getManifestDisk();

        if (!Storage::disk($disk)->exists($this->part->manifestPath)) {
            return 0;
        }

        $path = Storage::disk($disk)->path($this->part->manifestPath);
        $file = new \SplFileObject($path, 'r');
        $file->setFlags(\SplFileObject::READ_CSV | \SplFileObject::SKIP_EMPTY | \SplFileObject::DROP_NEW_LINE);
        $file->setCsvControl(File::$delimiter, File::$enclosure, File::$escape);

        $file->seek(PHP_INT_MAX);

        return $file->key();
    }

    /**
     * @return void
     */
    public function calculate(): void
    {
        $startTime = $this->getCurrentTime();
        $filesProcessed = 0;

        if ($this->part->status === $this->part->getManifestPendingStatus()) {
            // We set the offset to 0 because the finder starts from 0
            $this->part->offset = 0;
            $this->part->markAs($this->part->getManifestInProgressStatus());
        }

        $disk = $this->part->type;
        $limit = $this->part->limit;
        $excluded = Collection::make($this->part->excludedFiles);
        $excludedExtensions = Collection::make($this->part->excludedExtensions);

        $offset = $this->part->offset;

        $hasher = $this->createHasher();

        Log::debug('Manifest: Starting', [
            'part' => get_class($this->part),
            'type' => $this->part->type,
            'status' => $this->part->status,
            'offset' => $this->part->offset,
            'limit' => $this->part->limit,
            'hash_algorithm' => $hasher->getAlgorithm(),
            'force_full_hash' => $hasher->isForceFullHash(),
        ]);

        /**
         * @var \SplFileInfo[] $files
         */
        $files = File::finderWithLimitter($disk, $offset, $limit);

        $buffer = [];

        foreach ($files as $file) {
            // Increment the offset
            $offset++;
            $filesProcessed++;

            if ($this->shouldSkip($file, $excluded, $excludedExtensions)) {
                continue;
            }

            // Check memory usage periodically
            if ($filesProcessed % 500 === 0 && $this->checkMemoryUsage()) {
                Log::warning('Manifest: High memory usage', [
                    'type' => $this->part->type,
                    'offset' => $offset,
                    'filesProcessed' => $filesProcessed,
                    'memory_usage' => memory_get_usage(true),
                    'memory_peak' => memory_get_peak_usage(true),
                ]);

                // Force write buffer and cleanup
                if (!empty($buffer)) {
                    $this->writeManifest($buffer, $offset);
                    $buffer = [];
                }

                // Force garbage collection
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }

            $item = $this->mapItem($file, $hasher);

            if ($item === null) {
                continue;
            }

            // Escape the path to protect the delimiter
            $item['path'] = sprintf('"%s"', Str::replace('"', '""', $item['path']));

            $buffer[] = implode($this->delimiter, $item);

            // In some hosting providers, the process is really slow to read the file tree, so we need to limit it.
            if ($this->isTimeExceeded($startTime, $this->maxTime)) {
                Log::debug('Manifest: Max time exceeded', [
                    'type' => $this->part->type,
                    'status' => $this->part->status,
                    'offset' => $offset,
                    'limit' => $limit,
                ]);

                if (!empty($buffer)) {
                    $this->writeManifest($buffer, $offset);
                    $buffer = [];
                }

                break;
            }

            // Append the buffer
            if (count($buffer) >= $this->bufferSize) {
                $this->writeManifest($buffer, $offset);

                // Reset the buffer
                $buffer = [];
            }
        }

        // Append the remaining files
        if (!empty($buffer)) {
            $this->writeManifest($buffer, $offset);

            // Reset the buffer
            unset($buffer);

            // Force garbage collection after final write
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }

        // We don't know how many files we have, so we need search for more while we have files
        if ($filesProcessed > 0) {
            Log::debug('Manifest: Files found, re-dispatching', [
                'type' => $this->part->type,
                'status' => $this->part->status,
                'offset' => $offset,
                'limit' => $limit,
            ]);

            // Sometimes if the user has excluded files, the offset is not updated correctly because never write the manifest file
            $this->part->offset = $offset;

            dispatch(new CalculateManifestJob($this->part));

            return;
        }

        // When we don't have files, we need to update the total items
        $totalItems = $this->count();

        Log::debug('Manifest: Enumeration complete', [
            'type' => $this->part->type,
            'status' => $this->part->status,
            'offset' => $offset,
            'limit' => $limit,
            'totalItems' => $totalItems,
        ]);

        if ($totalItems > 0) {
            $this->part->totalItems = $totalItems;
            $this->part->markAs($this->part->getManifestDoneStatus());
        } else {
            $this->part->markAs($this->part->getManifestExcludedStatus());
        }
    }
}
