<?php

namespace Modular\Connector\Facades;

use Modular\Connector\Services\ManagerServer;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Facade;

class Server extends Facade
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
        return ManagerServer::class;
    }
}
