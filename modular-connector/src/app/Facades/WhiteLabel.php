<?php

namespace Modular\Connector\Facades;

use Modular\Connector\Services\ManagerWhiteLabel;
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
        return ManagerWhiteLabel::class;
    }
}
