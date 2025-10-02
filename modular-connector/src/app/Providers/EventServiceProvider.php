<?php

namespace Modular\Connector\Providers;

use Modular\Connector\Backups\Facades\Backup;
use Modular\Connector\Events\ManagerHealthUpdated;
use Modular\Connector\Events\ManagerItemsActivated;
use Modular\Connector\Events\ManagerItemsDeactivated;
use Modular\Connector\Events\ManagerItemsDeleted;
use Modular\Connector\Events\ManagerItemsInstalled;
use Modular\Connector\Events\ManagerItemsUpdated;
use Modular\Connector\Events\ManagerItemsUpgraded;
use Modular\Connector\Events\ManagerSafeUpgradeBackedUp;
use Modular\Connector\Events\ManagerSafeUpgradeCleanedUp;
use Modular\Connector\Events\ManagerSafeUpgradeRolledBack;
use Modular\Connector\Facades\Server;
use Modular\Connector\Listeners\HookEventListener;
use Modular\Connector\Listeners\PostUpgradeEventListener;
use Modular\Connector\Optimizer\Events\ManagerOptimizationInformation;
use Modular\Connector\Optimizer\Events\ManagerOptimizationUpdated;
use Modular\ConnectorDependencies\Illuminate\Queue\Events\WorkerStopping;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Event;
use Modular\ConnectorDependencies\Illuminate\Support\ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * Register the application's event listeners.
     *
     * @return void
     */
    public function boot()
    {
        Event::listen(ManagerItemsUpdated::class, HookEventListener::class);

        Event::listen(ManagerItemsUpgraded::class, HookEventListener::class);
        Event::listen(ManagerItemsUpgraded::class, PostUpgradeEventListener::class);
        Event::listen(ManagerItemsInstalled::class, PostUpgradeEventListener::class);

        Event::listen(ManagerItemsActivated::class, HookEventListener::class);
        Event::listen(ManagerItemsDeactivated::class, HookEventListener::class);
        Event::listen(ManagerItemsInstalled::class, HookEventListener::class);
        Event::listen(ManagerItemsDeleted::class, HookEventListener::class);
        Event::listen(ManagerHealthUpdated::class, HookEventListener::class);

        Event::listen(ManagerSafeUpgradeBackedUp::class, HookEventListener::class);
        Event::listen(ManagerSafeUpgradeCleanedUp::class, HookEventListener::class);
        Event::listen(ManagerSafeUpgradeRolledBack::class, HookEventListener::class);

        Event::listen(WorkerStopping::class, function (WorkerStopping $event) {
            Server::maintenanceMode(false);
        });

        Event::listen(ManagerOptimizationInformation::class, HookEventListener::class);
        Event::listen(ManagerOptimizationUpdated::class, HookEventListener::class);

        Backup::listeners();
    }
}
