<?php

namespace Modular\Connector\Backups\Iron\Events;

use Modular\Connector\Backups\Iron\BackupPart;
use Modular\Connector\Events\AbstractEvent;
use Modular\ConnectorDependencies\Illuminate\Support\Collection;

class ManagerBackupPartsCalculated extends AbstractEvent
{
    /**
     * @param Collection<BackupPart> $parts
     */
    public function __construct(Collection $parts)
    {
        $mrid = $parts->first()->mrid;
        $siteBackup = $parts->first()->siteBackup;

        parent::__construct($mrid, [
            'site_backup' => $siteBackup,
            'parts' => $parts->map(fn(BackupPart $part) => [
                'type' => $part->type,
                'batch' => $part->batch,
                'offset' => 0,
                'total_items' => $part->totalItems ?: 0,
                'status' => $part->status,
            ])->toArray(),
        ]);
    }
}
