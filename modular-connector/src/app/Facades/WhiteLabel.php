<?php

namespace Modular\Connector\Facades;

use Modular\ConnectorDependencies\Illuminate\Support\Facades\Facade;

class WhiteLabel extends Facade
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
        return 'manager-white-label';
    }
}
