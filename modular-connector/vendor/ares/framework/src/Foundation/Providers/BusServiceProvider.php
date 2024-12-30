<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Providers;

use Modular\ConnectorDependencies\Illuminate\Bus\BatchRepository;
use Modular\ConnectorDependencies\Illuminate\Bus\DatabaseBatchRepository;
use Modular\ConnectorDependencies\Illuminate\Bus\Dispatcher;
use Modular\ConnectorDependencies\Illuminate\Contracts\Bus\Dispatcher as DispatcherContract;
use Modular\ConnectorDependencies\Illuminate\Contracts\Bus\QueueingDispatcher as QueueingDispatcherContract;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\Factory as QueueFactoryContract;
use Modular\ConnectorDependencies\Illuminate\Contracts\Support\DeferrableProvider;
use Modular\ConnectorDependencies\Illuminate\Support\ServiceProvider;
class BusServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(Dispatcher::class, function ($app) {
            return new Dispatcher($app, function ($connection = null) use ($app) {
                return $app[QueueFactoryContract::class]->connection($connection);
            });
        });
        $this->app->alias(Dispatcher::class, DispatcherContract::class);
        $this->app->alias(Dispatcher::class, QueueingDispatcherContract::class);
    }
    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return [Dispatcher::class, DispatcherContract::class, QueueingDispatcherContract::class, BatchRepository::class, DatabaseBatchRepository::class];
    }
}
