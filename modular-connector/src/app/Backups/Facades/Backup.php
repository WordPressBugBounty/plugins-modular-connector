<?php

namespace Modular\Connector\Backups\Facades;

use Modular\Connector\Backups\BackupManager;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Facade;

class Backup extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     *
     * @throws \RuntimeException
     */
    protected static function getFacadeAccessor()
    {
        return BackupManager::class;
    }
}
