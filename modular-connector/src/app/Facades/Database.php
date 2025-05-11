<?php

namespace Modular\Connector\Facades;

use Modular\Connector\Services\ServiceDatabase;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Facade;

class Database extends Facade
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
        return ServiceDatabase::class;
    }
}
