<?php

namespace Modular\Connector\Backups\Iron\Jobs;

use Modular\Connector\Backups\Iron\BackupPart;
use Modular\Connector\Backups\Iron\Events\ManagerBackupPartUpdated;
use Modular\Connector\Backups\Phantom\Helpers\File;
use Modular\ConnectorDependencies\Illuminate\Bus\Queueable;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldQueue;
use Modular\ConnectorDependencies\Illuminate\Foundation\Bus\Dispatchable;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Storage;

class DatabaseCompressJob implements ShouldQueue
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
        $isCancelled = $part->isCancelled();

        if ($isCancelled) {
            return;
        }

        $part->markAs(ManagerBackupPartUpdated::STATUS_IN_PROGRESS);

        try {
            $filename = $part->getFileNameWithExtension('sql');

            if (!Storage::disk('backups')->exists($filename)) {
                throw new \Exception('File not found: ' . $filename);
            }

            $path = Storage::disk('backups')->path($filename);
            $zip = File::openZip($part->getPath('zip'));

            File::addToZip($zip, [
                'type' => 'file',
                'realpath' => $path,
                'path' => 'database.sql',
            ]);

            // Close the zip file after added the files
            File::closeZip($zip);

            Storage::disk('backups')->delete($filename);

            $part->markAs(ManagerBackupPartUpdated::STATUS_UPLOAD_PENDING);
        } catch (\Throwable $e) {
            Log::error($e);

            $part->markAsFailed(ManagerBackupPartUpdated::STATUS_FAILED_EXPORT_DATABASE, $e);
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
