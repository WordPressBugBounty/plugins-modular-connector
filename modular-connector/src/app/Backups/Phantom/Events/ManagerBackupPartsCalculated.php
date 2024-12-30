<?php

namespace Modular\Connector\Backups\Phantom\Events;

use Modular\Connector\Backups\BackupOptions;
use Modular\Connector\Backups\Phantom\BackupPart;
use Modular\ConnectorDependencies\Illuminate\Support\Collection;

class ManagerBackupPartsCalculated extends AbstractBackupEvent
{
    /**
     * @param Collection<BackupPart> $parts
     */
    public function __construct(BackupOptions $options, Collection $parts)
    {
        parent::__construct($options->mrid, [
            'parts' => $parts->map(fn(BackupPart $part) => $part->toArray())
                ->toArray(),
        ]);
    }
}
