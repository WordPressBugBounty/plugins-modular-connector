<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Cache;

use Modular\ConnectorDependencies\Illuminate\Cache\CacheServiceProvider as IlluminateCacheServiceProvider;
class CacheServiceProvider extends IlluminateCacheServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        parent::register();
        $this->app['cache']->extend('wordpress', function ($app, $config) {
            return new WordPressStoreRepository(new WordPressStore($config['prefix']));
        });
    }
}
