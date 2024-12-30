<?php

namespace Modular\Connector\Backups\Phantom\Listeners;

use Modular\Connector\Backups\Facades\Backup;
use Modular\Connector\Backups\Phantom\BackupPart;
use Modular\ConnectorDependencies\Illuminate\Support\Str;

class BackupRemoveEventListener
{
    /**
     * @param $event
     * @return void
     */
    public static function handle($event)
    {
        $status = $event->payload['status'];

        if (Str::startsWith($status, 'failed') || $status === BackupPart::PART_STATUS_DONE) {
            Backup::remove($event->payload['options']['name']);
        }
    }
}
