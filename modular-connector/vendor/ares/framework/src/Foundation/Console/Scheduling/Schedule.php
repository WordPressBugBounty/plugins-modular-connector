<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Console\Scheduling;

use Modular\ConnectorDependencies\Illuminate\Console\Scheduling\CacheEventMutex;
use Modular\ConnectorDependencies\Illuminate\Console\Scheduling\CacheSchedulingMutex;
use Modular\ConnectorDependencies\Illuminate\Console\Scheduling\EventMutex;
use Modular\ConnectorDependencies\Illuminate\Console\Scheduling\SchedulingMutex;
use Modular\ConnectorDependencies\Illuminate\Container\Container;
use Modular\ConnectorDependencies\Illuminate\Contracts\Container\BindingResolutionException;
use Modular\ConnectorDependencies\Illuminate\Support\Collection;
use Modular\ConnectorDependencies\Illuminate\Support\Traits\Macroable;
class Schedule
{
    use Macroable;
    /**
     * @var array
     */
    public array $events = [];
    /**
     * The event mutex implementation.
     *
     * @var \Illuminate\Console\Scheduling\EventMutex
     */
    protected $eventMutex;
    /**
     * The scheduling mutex implementation.
     *
     * @var \Illuminate\Console\Scheduling\SchedulingMutex
     */
    protected $schedulingMutex;
    /**
     * The timezone the date should be evaluated on.
     *
     * @var \DateTimeZone|string
     */
    protected $timezone;
    public function __construct($timezone = null)
    {
        $this->timezone = $timezone;
        if (!class_exists(Container::class)) {
            throw new \RuntimeException('A container implementation is required to use the scheduler. Please install the illuminate/container package.');
        }
        $container = Container::getInstance();
        $this->eventMutex = $container->bound(EventMutex::class) ? $container->make(EventMutex::class) : $container->make(CacheEventMutex::class);
        $this->schedulingMutex = $container->bound(SchedulingMutex::class) ? $container->make(SchedulingMutex::class) : $container->make(CacheSchedulingMutex::class);
    }
    /**
     * Compile parameters for a command.
     *
     * @param array $parameters
     * @return array
     */
    protected function compileParameters(array $parameters): array
    {
        return Collection::make($parameters)->mapWithKeys(function ($value, $key) {
            $key = ltrim($key, '-');
            return [$key => $value];
        })->toArray();
    }
    /**
     * Add a new Artisan command event to the schedule.
     *
     * @param string $command
     * @param array $parameters
     * @return Event
     * @throws BindingResolutionException
     */
    public function command($command, array $parameters = [])
    {
        if (!class_exists($command)) {
            throw new BindingResolutionException("Command class not found: {$command}");
        }
        $parameters = $this->compileParameters($parameters);
        $command = Container::getInstance()->make($command, ['parameters' => $parameters]);
        return $this->exec($command);
    }
    /**
     * Add a new command event to the schedule.
     *
     * @param string $command
     * @return Event
     */
    public function exec($command): Event
    {
        $this->events[] = $event = new Event($this->eventMutex, $command, $this->timezone);
        return $event;
    }
    /**
     * Determine if the server is allowed to run this event.
     *
     * @param \Illuminate\Console\Scheduling\Event $event
     * @param \DateTimeInterface $time
     * @return bool
     */
    public function serverShouldRun(Event $event, \DateTimeInterface $time)
    {
        return $this->schedulingMutex->create($event, $time);
    }
    /**
     * Get all of the events on the schedule that are due.
     *
     * @param \Illuminate\Contracts\Foundation\Application $app
     * @return \Illuminate\Support\Collection
     */
    public function dueEvents($app)
    {
        return Collection::make($this->events)->filter->isDue($app);
    }
}
