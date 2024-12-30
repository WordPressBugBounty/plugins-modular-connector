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
use Modular\Connector\Listeners\HookEventListener;
use Modular\Connector\Listeners\UpgradeTranslationsEventListener;
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
        Event::listen(ManagerItemsUpgraded::class, UpgradeTranslationsEventListener::class);

        Event::listen(ManagerItemsActivated::class, HookEventListener::class);
        Event::listen(ManagerItemsDeactivated::class, HookEventListener::class);
        Event::listen(ManagerItemsInstalled::class, HookEventListener::class);
        Event::listen(ManagerItemsDeleted::class, HookEventListener::class);
        Event::listen(ManagerHealthUpdated::class, HookEventListener::class);

        Backup::listeners();
    }
}
