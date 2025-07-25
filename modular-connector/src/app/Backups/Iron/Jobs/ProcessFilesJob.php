<?php

namespace Modular\Connector\Backups\Iron\Jobs;

use Modular\Connector\Backups\Iron\BackupPart;
use Modular\Connector\Backups\Iron\Events\ManagerBackupPartUpdated;
use Modular\Connector\Backups\Iron\Helpers\File;
use Modular\Connector\Backups\Iron\Helpers\HasMaxTime;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldQueue;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Storage;
use function Modular\ConnectorDependencies\dispatch;

class ProcessFilesJob implements ShouldQueue
{
    use HasMaxTime;

    /**
     * @var BackupPart
     */
    protected BackupPart $part;

    /**
     * @var string
     */
    protected string $disk;

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
        $this->queue = 'backups';
    }

    /**
     * @return void
     */
    public function handle()
    {
        $part = $this->part;

        if ($part->isCancelled()) {
            Log::debug('ProcessFilesJob cancelled', [
                'part' => $part->mrid,
                'status' => $part->status,
            ]);

            return;
        }

        Log::debug('Try to process files job', [
            'part' => $part->mrid,
            'status' => $part->status,
            'offset' => $part->offset,
            'limit' => $part->limit,
            'batchSize' => $part->batchSize,
            'batchMaxFileSize' => $part->batchMaxFileSize,
        ]);

        $part->markAs(ManagerBackupPartUpdated::STATUS_IN_PROGRESS);

        $startTime = $this->getCurrentTime();

        try {
            $zip = File::openZip($part->getPath('zip'));

            $offset = $part->offset;

            Log::debug('Read progress', [
                'type' => $part->type,
                'offset' => $offset,
                'limit' => $part->limit,
            ]);

            $files = $part->nextFiles($offset, $part->limit);
            $processedFiles = 0;

            foreach ($files as $file) {
                $processedFiles++;
                
                if ($file['timestamp'] <= $part->timestamp) {
                    continue;
                }

                $file['realpath'] = Storage::disk($part->type)->path($file['path']);

                File::addToZip($zip, $file);

                // Calculate compressed file size based on file type
                $itemSize = $file['size'];
                $compressionRatio = File::getCompressionRatio($file);
                $part->batchSize += $itemSize * $compressionRatio;

                if ($this->checkIfBatchSizeIsOversize($zip, $offset + $processedFiles, $startTime)) {
                    break;
                }
            }

            $this->checkFilesIsReady($zip, $offset + $processedFiles);
        } catch (\Throwable $e) {
            Log::error($e);

            $part->markAsFailed(ManagerBackupPartUpdated::STATUS_FAILED_EXPORT_FILES, $e);
        }
    }

    /**
     * @param \ZipArchive $zip
     * @param $offset
     * @param int $startTime
     * @return bool
     * @throws \ErrorException
     */
    public function checkIfBatchSizeIsOversize(\ZipArchive &$zip, $offset, int $startTime): bool
    {
        // If we reach the limit of files we need to upload
        if ($offset > $this->part->totalItems) {
            Log::debug('Batch items reached the limit');

            // Close the zip file after added the files
            File::closeZip($zip);

            $zip = null;

            return true;
        } elseif ($this->part->batchSize >= $this->part->batchMaxFileSize) {
            Log::debug('Batch items is oversize');

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
                if (!$this->part->isDone()) {
                    $newPart = clone $this->part;

                    $newPart->batchSize = 0;
                    $newPart->batch++;
                    $newPart->markAs(ManagerBackupPartUpdated::STATUS_PENDING);

                    dispatch(new ProcessFilesJob($newPart));
                }

                $zip = null;

                return true;
            }

            $zip = File::openZip($this->part->getPath('zip'));
        } elseif ($this->isTimeExceeded($startTime, $this->maxTime)) {
            Log::debug('Batch items max time exceeded');

            // Close the zip file after added the files
            File::closeZip($zip);

            $zip = null;

            return true;
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
        Log::debug('Check if files is ready', [
            'part' => $this->part->type,
            'batch' => $this->part->batch,
            'offset' => $offset,
            'totalItems' => $this->part->totalItems,
            'status' => $this->part->status,
            'batchSize' => $this->part->batchSize,
            'batchMaxFileSize' => $this->part->batchMaxFileSize,
        ]);

        // Close the zip file after added the files
        if ($zip instanceof \ZipArchive) {
            File::closeZip($zip);
        }

        $this->part->offset = $offset;

        // Get real size of zip file
        $zipPath = $this->part->getPath('zip');
        $zipExists = file_exists($zipPath);

        $this->part->batchSize = $zipExists ? $this->part->getPathSize('zip') : 0;

        if ($this->part->isDone()) {
            Log::debug('Part is done');

            // When the backup is incremental there is the possibility that the zip file is empty
            $status = $zipExists ? ManagerBackupPartUpdated::STATUS_UPLOAD_PENDING : ManagerBackupPartUpdated::STATUS_DONE;

            $this->part->markAs($status);
        } elseif ($this->part->status === ManagerBackupPartUpdated::STATUS_IN_PROGRESS) {
            Log::debug('Part is in progress');

            $this->part->markAs(ManagerBackupPartUpdated::STATUS_IN_PROGRESS, true, ['force_redispatch' => true]);
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
