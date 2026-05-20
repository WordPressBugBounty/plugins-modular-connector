<?php

namespace Modular\Connector\BrokenLinks\Facades;

use Modular\Connector\BrokenLinks\BrokenLinkManager;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Facade;

class BrokenLink extends Facade
{
    /**
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return BrokenLinkManager::class;
    }
}
