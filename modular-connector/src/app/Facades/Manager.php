<?php

namespace Modular\Connector\Facades;

use Modular\Connector\Services\Manager as ManagerService;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Facade;

class Manager extends Facade
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
        return ManagerService::class;
    }
}
