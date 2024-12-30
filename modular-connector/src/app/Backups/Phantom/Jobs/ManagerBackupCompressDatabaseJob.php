<?php

namespace Modular\Connector\Backups\Phantom\Jobs;

use Modular\Connector\Backups\Phantom\BackupPart;
use Modular\Connector\Facades\Manager;
use Modular\Connector\Services\Helpers\File;
use Modular\ConnectorDependencies\Illuminate\Bus\Queueable;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldQueue;
use Modular\ConnectorDependencies\Illuminate\Foundation\Bus\Dispatchable;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Storage;

class ManagerBackupCompressDatabaseJob implements ShouldQueue
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
            $filename = $part->options->name . '.sql';
            $path = Storage::disk('backup')->path($filename);

            if (!Storage::disk('backup')->exists($filename)) {
                Storage::disk('backup')->put($filename, '');
            }

            Manager::driver('database')->dump($path, $part->options);

            $zip = File::openZip($part->getZipPath());

            File::addToZip($zip, [
                'type' => 'file',
                'realpath' => $path,
                'path' => 'database.sql',
            ]);

            // Close the zip file after added the files
            File::closeZip($zip);

            Storage::disk('backup')->delete($filename);

            $part->markAsUploadPending();
        } catch (\Throwable $e) {
            Log::error($e);

            $part->markAsFailed(BackupPart::STATUS_FAILED_EXPORT_DATABASE, $e);
        }
    }
}
