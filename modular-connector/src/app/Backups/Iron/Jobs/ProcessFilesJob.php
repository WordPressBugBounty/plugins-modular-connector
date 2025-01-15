<?php

namespace Modular\Connector\Backups\Iron\Jobs;

use Modular\Connector\Backups\Iron\BackupPart;
use Modular\Connector\Backups\Iron\Events\ManagerBackupPartUpdated;
use Modular\Connector\Backups\Iron\Helpers\File;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldQueue;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Storage;
use function Modular\ConnectorDependencies\dispatch;

class ProcessFilesJob implements ShouldQueue
{
    /**
     * @var BackupPart
     */
    protected BackupPart $part;

    /**
     * @var string
     */
    protected string $disk;

    /**
     * @param BackupPart $part
     */
    public function __construct(BackupPart $part)
    {
        $this->part = $part;
        $this->queue = 'backups';
    }

    /**
     * @return void
     */
    public function handle()
    {
        $part = $this->part;
        $isCancelled = $part->isCancelled();

        if ($isCancelled) {
            return;
        }

        $part->markAs(ManagerBackupPartUpdated::STATUS_IN_PROGRESS);

        $chunkSize = $part->limit;
        $chucks = ceil($part->limit / $chunkSize);

        try {
            $zip = File::openZip($part->getPath('zip'));

            $offset = $part->offset();

            for ($i = 0; $i < $chucks; $i++) {
                $limit = $offset + $chunkSize - 1;

                $files = $part->nextFiles($offset, $limit);

                $break = false;

                foreach ($files as $file) {
                    if ($file['timestamp'] <= $part->timestamp) {
                        ++$offset;
                        continue;
                    }

                    $file['realpath'] = Storage::disk($part->type)->path($file['path']);

                    File::addToZip($zip, $file);
                    ++$offset;

                    // TODO Calculate compressed file size in based file type
                    $itemSize = $file['size'];
                    $part->batchSize += $itemSize * .9;

                    if ($this->checkIfBatchSizeIsOversize($zip, $offset)) {
                        $break = true;
                        break;
                    }
                }

                if ($break) {
                    break;
                }
            }

            $this->checkFilesIsReady($zip, $offset);
        } catch (\Throwable $e) {
            $part->markAsFailed(ManagerBackupPartUpdated::STATUS_FAILED_EXPORT_FILES, $e);
        }
    }

    /**
     * @param \ZipArchive $zip
     * @param $offset
     * @return bool
     * @throws \ErrorException
     */
    public function checkIfBatchSizeIsOversize(\ZipArchive &$zip, $offset): bool
    {
        // If we reach the limit of files we need to upload
        if ($offset > $this->part->totalItems) {
            // Close the zip file after added the files
            File::closeZip($zip);

            $zip = null;

            return true;
        } elseif ($this->part->batchSize >= $this->part->batchMaxFileSize) {
            // Close the zip file after added the files
            File::closeZip($zip);

            // Get real size of zip file
            $this->part->batchSize = $this->part->getPathSize('zip');

            // If the zip file is bigger than the limit we need to create a new part
            if ($this->part->batchSize >= $this->part->batchMaxFileSize) {
                // Save the current offset
                $this->part->offset = $offset;

                // Send current part to upload
                $this->part->markAs(ManagerBackupPartUpdated::STATUS_UPLOAD_PENDING);

                // Create new part upload
                $newPart = clone $this->part;

                $newPart->batchSize = 0;
                $newPart->batch++;
                $newPart->markAs(ManagerBackupPartUpdated::STATUS_PENDING);

                dispatch(new ProcessFilesJob($newPart));

                $zip = null;

                return true;
            }

            $zip = File::openZip($this->part->getPathSize('zip'));
        }

        return false;
    }

    /**
     * Check if the part is ready
     *
     * @param \ZipArchive|null $zip
     * @param $offset
     * @return void
     * @throws \ErrorException
     */
    public function checkFilesIsReady(?\ZipArchive $zip, $offset): void
    {
        // Close the zip file after added the files
        if ($zip instanceof \ZipArchive) {
            File::closeZip($zip);
        }

        $this->part->offset = $offset;

        // Get real size of zip file
        $zipPath = $this->part->getPath('zip');
        $zipExists = file_exists($zipPath);

        $this->part->batchSize = $zipExists ? $this->part->getPathSize('zip') : 0;

        if ($this->part->offset >= $this->part->totalItems) {
            // When the backup is incremental there is the possibility that the zip file is empty
            $status = $zipExists ? ManagerBackupPartUpdated::STATUS_UPLOAD_PENDING : ManagerBackupPartUpdated::STATUS_DONE;

            $this->part->markAs($status);
        } elseif ($this->part->status === ManagerBackupPartUpdated::STATUS_IN_PROGRESS) {
            dispatch(new ProcessFilesJob($this->part));
        }
    }

    /**
     * @return string
     */
    public function uniqueId(): string
    {
        return $this->part->mrid . '-' . $this->part->type . '-' . $this->part->batch;
    }
}
