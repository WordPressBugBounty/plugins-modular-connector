<?php

namespace Modular\Connector\Backups\Iron\Manifest;

use Modular\Connector\Backups\Iron\BackupPart;
use Modular\Connector\Backups\Iron\Events\ManagerBackupPartUpdated;
use Modular\Connector\Backups\Iron\Helpers\File;
use Modular\Connector\Backups\Iron\Helpers\HasMaxTime;
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

        if ($currentOffset > 0 && !Storage::disk('backups')->exists($this->part->manifestPath)) {
            throw new \RuntimeException('Manifest file not found');
        }

        $content = implode("\n", $buffer);

        // When the offset is 0, we need to create the file
        if ($currentOffset === 0) {
            Storage::disk('backups')->put($this->part->manifestPath, $content);
        } else {
            Storage::disk('backups')->append($this->part->manifestPath, $content);
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

        $file->seek(PHP_INT_MAX);

        return $file->key();
    }

    /**
     * @return void
     */
    public function calculate(): void
    {
        $startTime = $this->getCurrentTime();

        // We need to increment the offset to avoid processing the same files
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
        $files = File::finderWithLimitter($disk, $excluded, $offset, $limit);

        $hasFiles = false;
        $buffer = [];
        $chunkSize = 100;

        foreach ($files as $file) {
            $hasFiles = true;

            $file = File::mapItem($file, $disk);
            // Scape the path to protect the delimiter
            $file['path'] = sprintf('"%s"', Str::replace('"', '""', $file['path']));

            $buffer[] = implode($this->delimiter, $file);

            // Increment the offset
            $offset++;

            // In some hosting providers, the process is really slow to read the file tree, so we need to limit it.
            if ($this->isTimeExceeded($startTime, $this->maxTime)) {
                Log::debug('Manifest: Max time exceeded', [
                    'type' => $this->part->type,
                    'status' => $this->part->status,
                    'offset' => $offset,
                    'limit' => $limit,
                ]);

                break;
            }

            // Append the buffer
            if (count($buffer) >= $chunkSize) {
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
        }

        // We don't know how many files we have, so we need search for more while we have files
        if ($hasFiles) {
            Log::debug('Manifest: Files found', [
                'type' => $this->part->type,
                'status' => $this->part->status,
                'offset' => $offset,
                'limit' => $limit,
            ]);

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
