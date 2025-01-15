<?php

namespace Modular\Connector\Backups\Iron\Manifest;

use Modular\Connector\Backups\Iron\BackupPart;
use Modular\ConnectorDependencies\Illuminate\Bus\Queueable;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldQueue;
use Modular\ConnectorDependencies\Illuminate\Foundation\Bus\Dispatchable;

class CalculateManifestJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    /**
     * @var BackupPart
     */
    protected BackupPart $part;

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
        Manifest::create($this->part)
            ->includeHeaders(true)
            ->calculate();
    }

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return $this->part->mrid;
    }
}
