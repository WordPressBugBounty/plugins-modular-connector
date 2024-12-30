<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Console\Scheduling;

use Modular\ConnectorDependencies\Illuminate\Console\Scheduling\Event as IlluminateEvent;
use Modular\ConnectorDependencies\Illuminate\Contracts\Container\Container;
class Event extends IlluminateEvent
{
    /**
     * Run the given event.
     *
     * @param \Illuminate\Contracts\Container\Container $container
     * @return void
     *
     * @throws \Throwable
     */
    public function run(Container $container)
    {
        if ($this->shouldSkipDueToOverlapping()) {
            return;
        }
        $this->runCommandInForeground($container);
    }
    /**
     * @param Container $container
     * @return void
     */
    protected function runCommandInForeground(Container $container)
    {
        try {
            $this->callBeforeCallbacks($container);
            /**
             * @var \Ares\Framework\Foundation\Console\Command $command
             */
            $command = $this->command;
            $command->handle();
            $this->callAfterCallbacks($container);
        } finally {
            $this->removeMutex();
        }
    }
    /**
     * Determine if the event should skip because another process is overlapping.
     *
     * @return bool
     */
    public function shouldSkipDueToOverlapping()
    {
        return $this->withoutOverlapping && !$this->mutex->create($this);
    }
}
