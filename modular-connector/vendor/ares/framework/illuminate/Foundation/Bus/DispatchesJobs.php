<?php

namespace Modular\ConnectorDependencies\Illuminate\Foundation\Bus;

use Modular\ConnectorDependencies\Illuminate\Contracts\Bus\Dispatcher;
trait DispatchesJobs
{
    /**
     * Dispatch a job to its appropriate handler.
     *
     * @param  mixed  $job
     * @return mixed
     */
    protected function dispatch($job)
    {
        return \Modular\ConnectorDependencies\app(Dispatcher::class)->dispatch($job);
    }
    /**
     * Dispatch a job to its appropriate handler in the current process.
     *
     * @param  mixed  $job
     * @return mixed
     *
     * @deprecated Will be removed in a future Laravel version.
     */
    public function dispatchNow($job)
    {
        return \Modular\ConnectorDependencies\app(Dispatcher::class)->dispatchNow($job);
    }
    /**
     * Dispatch a job to its appropriate handler in the current process.
     *
     * Queueable jobs will be dispatched to the "sync" queue.
     *
     * @param  mixed  $job
     * @return mixed
     */
    public function dispatchSync($job)
    {
        return \Modular\ConnectorDependencies\app(Dispatcher::class)->dispatchSync($job);
    }
}
