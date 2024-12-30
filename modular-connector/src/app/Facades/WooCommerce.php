<?php

namespace Modular\Connector\Facades;

use Modular\Connector\Services\Manager\ManagerWooCommerce;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Facade;

class WooCommerce extends Facade
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
        return ManagerWooCommerce::class;
    }
}
