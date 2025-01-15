<?php

namespace Modular\Connector\Backups\Phantom\Jobs;

use Modular\Connector\Backups\Phantom\BackupPart;
use Modular\Connector\Backups\Phantom\Helpers\File;
use Modular\ConnectorDependencies\Illuminate\Bus\Queueable;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldQueue;
use Modular\ConnectorDependencies\Illuminate\Foundation\Bus\Dispatchable;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;

class ManagerBackupCompressFilesJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    /**
     * @var BackupPart
     */
    public BackupPart $part;

    /**
     * @param BackupPart $part
     */
    public function __construct(BackupPart $part)
    {
        $this->part = $part;
        $this->queue = 'backups';
    }

    /**
     * @throws \Throwable
     * @throws \ErrorException
     */
    public function handle()
    {
        $part = $this->part;
        $isCancelled = $part->options->isCancelled();

        if ($isCancelled) {
            return;
        }

        $part->markAsInProgress();

        try {
            $finder = $part->getFinder(true);

            $zip = File::openZip($part->getZipPath());

            $foundFiles = false;

            foreach ($finder as $item) {
                $foundFiles = true;

                $itemSize = $item->getSize();
                $item = File::mapItem($item);
                File::addToZip($zip, $item);

                $part->offset++;

                // We estimate that the files will be compressed to 90% of their original size.
                $part->batchSize += $itemSize * .9;

                if ($part->checkIfBatchSizeIsOversize($zip)) {
                    break;
                }
            }

            $part->checkFilesIsReady($zip, $foundFiles);
        } catch (\Throwable $e) {
            Log::error($e);

            $part->markAsFailed(BackupPart::STATUS_FAILED_EXPORT_FILES, $e);
        }
    }
}
