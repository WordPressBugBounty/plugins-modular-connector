<?php

namespace Modular\ConnectorDependencies\Illuminate\Events;

use Closure;
if (!function_exists('Modular\ConnectorDependencies\Illuminate\Events\queueable')) {
    /**
     * Create a new queued Closure event listener.
     *
     * @param  \Closure  $closure
     * @return \Illuminate\Events\QueuedClosure
     */
    function queueable(Closure $closure)
    {
        return new QueuedClosure($closure);
    }
}
