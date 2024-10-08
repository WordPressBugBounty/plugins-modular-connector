<?php

namespace Modular\ConnectorDependencies\Illuminate\Log;

use Modular\ConnectorDependencies\Illuminate\Support\ServiceProvider;
/** @internal */
class LogServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton('log', function ($app) {
            return new LogManager($app);
        });
    }
}
