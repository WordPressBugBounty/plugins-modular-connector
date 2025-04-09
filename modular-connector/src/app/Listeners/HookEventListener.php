<?php

namespace Modular\Connector\Listeners;

use Modular\Connector\Events\AbstractEvent;
use Modular\Connector\Jobs\Hooks\HookSendEventJob;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldQueue;
use function Modular\ConnectorDependencies\app;
use function Modular\ConnectorDependencies\dispatch;
use function Modular\ConnectorDependencies\dispatch_sync;

class HookEventListener
{
    /**
     * Handle the event.
     *
     * @param AbstractEvent $event
     * @return void
     */
    public function handle(AbstractEvent $event)
    {
        if ($event instanceof ShouldQueue) {
            app('log')->debug('Dispatching event to queue', ['event' => get_class($event)]);

            dispatch(new HookSendEventJob($event));
        } else {
            app('log')->debug('Dispatching event synchronously', ['event' => get_class($event)]);

            dispatch_sync(new HookSendEventJob($event));
        }
    }
}
