<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Queue;

use Modular\ConnectorDependencies\Ares\Framework\Foundation\Queue\Database\DatabaseConnector;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Queue\WordPress\WordpressQueueConnector;
use Modular\ConnectorDependencies\Illuminate\Contracts\Debug\ExceptionHandler;
use Modular\ConnectorDependencies\Illuminate\Queue\QueueServiceProvider as IlluminateQueueServiceProvider;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Facade;
class QueueServiceProvider extends IlluminateQueueServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        parent::register();
        $this->app['queue']->extend('wordpress', function () {
            global $wpdb;
            return new WordpressQueueConnector($wpdb);
        });
    }
    /**
     * Register the database queue connector.
     *
     * @param \Illuminate\Queue\QueueManager $manager
     * @return void
     */
    protected function registerDatabaseConnector($manager)
    {
        $manager->addConnector('database', function () {
            return new DatabaseConnector($this->app['db']);
        });
    }
    /**
     * Register the queue worker.
     *
     * @return void
     */
    protected function registerWorker()
    {
        $this->app->singleton('queue.worker', function ($app) {
            $isDownForMaintenance = function () {
                return $this->app->isDownForMaintenance();
            };
            $resetScope = function () use ($app) {
                if (method_exists($app['log']->driver(), 'withoutContext')) {
                    $app['log']->withoutContext();
                }
                $app->forgetScopedInstances();
                return Facade::clearResolvedInstances();
            };
            return new Worker($app['queue'], $app['events'], $app[ExceptionHandler::class], $isDownForMaintenance, $resetScope);
        });
    }
}
