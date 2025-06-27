<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Providers;

use Modular\ConnectorDependencies\Illuminate\Console\Scheduling\Schedule;
use Modular\ConnectorDependencies\Illuminate\Contracts\Http\Kernel;
use Modular\ConnectorDependencies\Illuminate\Support\ServiceProvider;
class FoundationServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        parent::register();
        $this->registerConsoleSchedule();
    }
    /**
     * Register the console schedule implementation.
     *
     * @return void
     */
    public function registerConsoleSchedule()
    {
        $this->app->singleton(Schedule::class, function ($app) {
            return $app->make(Kernel::class)->resolveConsoleSchedule();
        });
    }
}
