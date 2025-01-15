<?php

namespace Modular\Connector\Backups\Phantom\Events;


use Modular\Connector\Backups\Phantom\BackupOptions;

class ManagerBackupFailedCreation extends AbstractBackupEvent
{
    /***
     * @param BackupOptions $part
     * @param \Throwable $e
     */
    public function __construct(BackupOptions $part, \Throwable $e)
    {
        parent::__construct(
            $part->mrid,
            [
                'options' => $part->toArray(),
            ] + [
                'error' => [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ],
                'status' => 'failed_in_creation',
            ]
        );
    }
}
