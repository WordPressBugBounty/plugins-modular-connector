<?php

namespace Modular\Connector\Backups\Iron\Manifest;

use Modular\Connector\Backups\Iron\BackupPart;
use Modular\Connector\Backups\Iron\Events\ManagerBackupPartUpdated;
use Modular\Connector\Backups\Iron\Helpers\File;
use Modular\ConnectorDependencies\Illuminate\Support\Collection;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Storage;
use function Modular\ConnectorDependencies\dispatch;

class Manifest
{
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
            $file['path'] = sprintf('"%s"', $file['path']);

            $buffer[] = implode($this->delimiter, $file);

            // Increment the offset
            $offset++;

            // Append the buffer
            if (count($buffer) >= $chunkSize) {
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
            dispatch(new CalculateManifestJob($this->part));
        } else {
            // When we don't have files, we need to update the total items
            $totalItems = $this->count();

            if ($totalItems > 0) {
                $this->part->totalItems = $totalItems;
                $this->part->markAs(ManagerBackupPartUpdated::STATUS_MANIFEST_UPLOAD_PENDING);
            } else {
                $this->part->markAs(ManagerBackupPartUpdated::STATUS_EXCLUDED);
            }
        }
    }
}
