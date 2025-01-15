<?php

namespace Modular\Connector\Backups\Phantom\Jobs;

use Modular\Connector\Backups\Phantom\BackupOptions;
use Modular\Connector\Backups\Phantom\BackupWorker;
use Modular\ConnectorDependencies\Illuminate\Bus\Queueable;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldQueue;
use Modular\ConnectorDependencies\Illuminate\Foundation\Bus\Dispatchable;

class ManagerBackupCalculateManifestJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    /**
     * @var BackupOptions
     */
    protected BackupOptions $options;

    public function __construct(BackupOptions $options)
    {
        $this->options = $options;
        $this->queue = 'backups';
    }

    public function handle()
    {
        $options = $this->options;

        $isCancelled = $options->isCancelled();

        if ($isCancelled) {
            return;
        }

        $worker = BackupWorker::getInstance()->calculateParts($options);

        if (!is_null($worker)) {
            $worker->dispatch();
        }
    }
}
