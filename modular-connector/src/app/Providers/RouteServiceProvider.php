<?php

namespace Modular\Connector\Providers;

use Modular\ConnectorDependencies\Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Route;
use function Modular\ConnectorDependencies\base_path;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     *
     * Route resolution is handled by ModularRouteResolver (registered as singleton).
     *
     * @return void
     */
    public function boot()
    {
        $this->routes(function () {
            Route::prefix('/api/modular-connector')
                ->middleware('api')
                ->group(base_path('routes/api.php'));
        });
    }
}
