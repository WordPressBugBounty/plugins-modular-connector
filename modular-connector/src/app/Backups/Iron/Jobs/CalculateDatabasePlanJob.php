<?php

namespace Modular\Connector\Backups\Iron\Jobs;

use Modular\Connector\Backups\Iron\BackupPart;
use Modular\Connector\Backups\Iron\Events\ManagerBackupPartUpdated;
use Modular\Connector\Facades\Manager;
use Modular\ConnectorDependencies\Illuminate\Bus\Queueable;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldQueue;
use Modular\ConnectorDependencies\Illuminate\Foundation\Bus\Dispatchable;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;

/**
 * The database counterpart of CalculateManifestJob: resolve the dump plan up front and tell
 * the API the exact segment count before any dumping starts.
 *
 * It speaks the same status language as the file manifests — manifest_in_progress while
 * planning, manifest_done carrying the definitive total_items — so the API learns "this
 * backup means N dumps" the moment the backup begins, not as the segments trickle in.
 * There is no manifest file to upload, so the upload statuses are skipped.
 */
class CalculateDatabasePlanJob implements ShouldQueue
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
     * @return void
     */
    public function handle()
    {
        $part = $this->part;

        if ($part->isCancelled()) {
            return;
        }

        $part->markAs(ManagerBackupPartUpdated::STATUS_MANIFEST_IN_PROGRESS);

        try {
            Manager::driver('database')->planDump($part);

            // MANIFEST_DONE keeps total_items in the event payload and dispatches the dump
            // job from markAs(), mirroring how file parts hand over to ProcessFilesJob. The
            // database_plan itself rides on every event via BackupPart::getDumpPlan().
            $part->markAs(ManagerBackupPartUpdated::STATUS_MANIFEST_DONE);
        } catch (\Throwable $e) {
            Log::error($e);

            $part->markAsFailed(ManagerBackupPartUpdated::STATUS_FAILED_EXPORT_DATABASE, $e);
        }
    }
}
