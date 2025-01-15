<?php

namespace Modular\Connector\Backups\Phantom\Events;

use Modular\Connector\Backups\Phantom\BackupOptions;
use Modular\Connector\Backups\Phantom\BackupPart;
use Modular\ConnectorDependencies\Illuminate\Support\Collection;

class ManagerBackupPartsCalculated extends AbstractBackupEvent
{
    /**
     * @param Collection<BackupPart> $parts
     */
    public function __construct(BackupOptions $backupPart, Collection $parts)
    {
        parent::__construct($backupPart->mrid, [
            'parts' => $parts->map(fn(BackupPart $part) => $part->toArray())
                ->toArray(),
        ]);
    }
}
