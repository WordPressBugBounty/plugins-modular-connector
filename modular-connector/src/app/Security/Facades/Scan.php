<?php

namespace Modular\Connector\Security\Facades;

use Modular\Connector\Security\ScanManager;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Facade;

class Scan extends Facade
{
    /**
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return ScanManager::class;
    }
}
