<?php

namespace Modular\ConnectorDependencies\Illuminate\Foundation\Bus;

use Modular\ConnectorDependencies\Illuminate\Contracts\Bus\Dispatcher;
use Modular\ConnectorDependencies\Illuminate\Support\Fluent;
trait Dispatchable
{
    /**
     * Dispatch the job with the given arguments.
     *
     * @return \Illuminate\Foundation\Bus\PendingDispatch
     */
    public static function dispatch(...$arguments)
    {
        return new PendingDispatch(new static(...$arguments));
    }
    /**
     * Dispatch the job with the given arguments if the given truth test passes.
     *
     * @param  bool  $boolean
     * @param  mixed  ...$arguments
     * @return \Illuminate\Foundation\Bus\PendingDispatch|\Illuminate\Support\Fluent
     */
    public static function dispatchIf($boolean, ...$arguments)
    {
        return $boolean ? new PendingDispatch(new static(...$arguments)) : new Fluent();
    }
    /**
     * Dispatch the job with the given arguments unless the given truth test passes.
     *
     * @param  bool  $boolean
     * @param  mixed  ...$arguments
     * @return \Illuminate\Foundation\Bus\PendingDispatch|\Illuminate\Support\Fluent
     */
    public static function dispatchUnless($boolean, ...$arguments)
    {
        return !$boolean ? new PendingDispatch(new static(...$arguments)) : new Fluent();
    }
    /**
     * Dispatch a command to its appropriate handler in the current process.
     *
     * Queueable jobs will be dispatched to the "sync" queue.
     *
     * @return mixed
     */
    public static function dispatchSync(...$arguments)
    {
        return \Modular\ConnectorDependencies\app(Dispatcher::class)->dispatchSync(new static(...$arguments));
    }
    /**
     * Dispatch a command to its appropriate handler in the current process.
     *
     * @return mixed
     *
     * @deprecated Will be removed in a future Laravel version.
     */
    public static function dispatchNow(...$arguments)
    {
        return \Modular\ConnectorDependencies\app(Dispatcher::class)->dispatchNow(new static(...$arguments));
    }
    /**
     * Dispatch a command to its appropriate handler after the current process.
     *
     * @return mixed
     */
    public static function dispatchAfterResponse(...$arguments)
    {
        return \Modular\ConnectorDependencies\app(Dispatcher::class)->dispatchAfterResponse(new static(...$arguments));
    }
    /**
     * Set the jobs that should run if this job is successful.
     *
     * @param  array  $chain
     * @return \Illuminate\Foundation\Bus\PendingChain
     */
    public static function withChain($chain)
    {
        return new PendingChain(static::class, $chain);
    }
}
