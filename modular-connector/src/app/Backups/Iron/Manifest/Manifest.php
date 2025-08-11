<?php

namespace Modular\Connector\Backups\Iron\Manifest;

use Modular\Connector\Backups\Iron\BackupPart;
use Modular\Connector\Backups\Iron\Events\ManagerBackupPartUpdated;
use Modular\Connector\Backups\Iron\Helpers\File;
use Modular\Connector\Backups\Iron\Helpers\HasMaxTime;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Http\HttpUtils;
use Modular\ConnectorDependencies\Illuminate\Support\Collection;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Storage;
use Modular\ConnectorDependencies\Illuminate\Support\Str;
use function Modular\ConnectorDependencies\dispatch;

class Manifest
{
    use HasMaxTime;

    /**
     * @var BackupPart
     */
    protected BackupPart $part;

    /**
     * @var string
     */
    protected $delimiter = ';';

    /**
     * @var int
     */
    protected int $bufferSize = 100;

    /**
     * @var string
     */
    protected $disk;

    /**
     * The maximum number of seconds a worker may live.
     *
     * @var int
     */
    protected int $maxTime = 90;

    /**
     * @param BackupPart $part
     */
    public function __construct(BackupPart $part)
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
     * @param BackupPart $part
     * @return string
     */
    public static function path(BackupPart $part): string
    {
        return sprintf('%s-%s-%s', $part->name, 'manifest', $part->type);
    }

    /**
     * @param BackupPart $part
     * @return self
     */
    public static function create(BackupPart $part): self
    {
        return new self($part);
    }

    /**
     * @param array $buffer
     * @param int $offset
     * @return void
     */
    private function writeManifest(array $buffer, int $offset): void
    {
        $currentOffset = $this->part->offset;
        $filePath = Storage::disk('backups')->path($this->part->manifestPath);

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
     * @return int
     */
    public function count(): int
    {
        if (!Storage::disk('backups')->exists($this->part->manifestPath)) {
            return 0;
        }

        $path = Storage::disk('backups')->path($this->part->manifestPath);
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

        if ($this->part->status === ManagerBackupPartUpdated::STATUS_PENDING) {
            // We set the offset to 0 because the finder starts from 0
            $this->part->offset = 0;
            $this->part->markAs(ManagerBackupPartUpdated::STATUS_MANIFEST_IN_PROGRESS);
        }

        $disk = $this->part->type;
        $limit = $this->part->limit;
        $excluded = Collection::make($this->part->excludedFiles);

        $offset = $this->part->offset;

        Log::debug('Try to Manifest', [
            'type' => $this->part->type,
            'status' => $this->part->status,
            'offset' => $this->part->offset,
            'limit' => $this->part->limit,
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

            if (File::shouldExclude($disk, $file, $excluded)) {
                continue;
            }

            // Check memory usage periodically
            if ($filesProcessed % 500 === 0 && $this->checkMemoryUsage()) {
                Log::warning('High memory usage detected during manifest generation', [
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
                    Log::debug('Forcing garbage collection due to high memory usage', [
                        'type' => $this->part->type,
                        'status' => $this->part->status,
                        'offset' => $offset,
                        'filesProcessed' => $filesProcessed,
                    ]);

                    gc_collect_cycles();
                }
            }

            $item = File::mapItem($file, $disk);

            // Scape the path to protect the delimiter
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
                Log::debug('Manifest: Buffer size exceeded', [
                    'type' => $this->part->type,
                    'status' => $this->part->status,
                    'offset' => $offset,
                    'limit' => $limit,
                ]);

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
                Log::debug('Forcing garbage collection after final write', [
                    'type' => $this->part->type,
                    'status' => $this->part->status,
                    'offset' => $offset,
                    'filesProcessed' => $filesProcessed,
                ]);

                gc_collect_cycles();
            }
        }

        // We don't know how many files we have, so we need search for more while we have files
        if ($filesProcessed > 0) {
            Log::debug('Manifest: Files found', [
                'type' => $this->part->type,
                'status' => $this->part->status,
                'offset' => $offset,
                'limit' => $limit,
            ]);

            // Sometimes if the user has excluded files, the offset is not updated correctly because never write the manifest file
            $this->part->offset = $offset;

            dispatch(new CalculateManifestJob($this->part));
        } else {
            // When we don't have files, we need to update the total items
            $totalItems = $this->count();

            Log::debug('Manifest: Dispatch uploading job?', [
                'type' => $this->part->type,
                'status' => $this->part->status,
                'offset' => $offset,
                'limit' => $limit,
                'totalItems' => $totalItems,
            ]);

            if ($totalItems > 0) {
                $this->part->totalItems = $totalItems;
                $this->part->markAs(ManagerBackupPartUpdated::STATUS_MANIFEST_UPLOAD_PENDING);
            } else {
                $this->part->markAs(ManagerBackupPartUpdated::STATUS_EXCLUDED);
            }
        }
    }
}
