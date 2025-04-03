<?php

namespace Modular\Connector\Backups\Iron\Jobs;

use Modular\Connector\Backups\Iron\BackupPart;
use Modular\Connector\Backups\Iron\Events\ManagerBackupPartUpdated;
use Modular\Connector\Facades\Manager;
use Modular\ConnectorDependencies\Illuminate\Bus\Queueable;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldQueue;
use Modular\ConnectorDependencies\Illuminate\Foundation\Bus\Dispatchable;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Storage;
use function Modular\ConnectorDependencies\dispatch;

class DatabaseDumpJob implements ShouldQueue
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

            $path = Storage::disk('backups')->path($filename);

            if (!Storage::disk('backups')->exists($filename)) {
                Storage::disk('backups')->put($filename, '');
            }

            Manager::driver('database')->dump($path, $part);

            dispatch(new DatabaseCompressJob($part));
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
