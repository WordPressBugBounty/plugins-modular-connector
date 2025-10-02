<?php

namespace Modular\Connector\Facades;

use Modular\Connector\Services\Manager\ManagerSafeUpgrade;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Facade;

class SafeUpgrade extends Facade
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
        return ManagerSafeUpgrade::class;
    }
}
