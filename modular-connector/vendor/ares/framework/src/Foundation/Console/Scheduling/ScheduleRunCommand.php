<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Console\Scheduling;

use Modular\ConnectorDependencies\Ares\Framework\Foundation\Console\Command;
use Modular\ConnectorDependencies\Illuminate\Console\Events\ScheduledTaskFailed;
use Modular\ConnectorDependencies\Illuminate\Console\Events\ScheduledTaskFinished;
use Modular\ConnectorDependencies\Illuminate\Console\Events\ScheduledTaskSkipped;
use Modular\ConnectorDependencies\Illuminate\Console\Events\ScheduledTaskStarting;
use Modular\ConnectorDependencies\Illuminate\Contracts\Container\BindingResolutionException;
use Modular\ConnectorDependencies\Illuminate\Contracts\Debug\ExceptionHandler;
use Modular\ConnectorDependencies\Illuminate\Contracts\Events\Dispatcher;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Date;
use Modular\ConnectorDependencies\Illuminate\Support\Str;
class ScheduleRunCommand extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'schedule:run';
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run the scheduled commands';
    /**
     * The 24 hour timestamp this scheduler command started running.
     *
     * @var \Illuminate\Support\Carbon
     */
    protected $startedAt;
    /**
     * The unique ID of this scheduler run.
     *
     * @var string
     */
    protected string $uuid;
    /**
     * Check if any events ran.
     *
     * @var bool
     */
    protected $eventsRan = \false;
    /**
     * The schedule instance.
     *
     * @var Schedule
     */
    protected $schedule;
    /**
     * The event dispatcher.
     *
     * @var \Illuminate\Contracts\Events\Dispatcher
     */
    protected $dispatcher;
    /**
     * The exception handler.
     *
     * @var \Illuminate\Contracts\Debug\ExceptionHandler
     */
    protected $handler;
    /**
     * Create a new command instance.
     *
     * @throws BindingResolutionException
     */
    public function __construct()
    {
        $this->startedAt = Date::now();
        $this->uuid = Str::uuid();
        parent::__construct();
    }
    /**
     * Run the event on a single server.
     *
     * @param Event $event
     * @return void
     */
    protected function runSingleServerEvent($event)
    {
        if ($this->schedule->serverShouldRun($event, $this->startedAt)) {
            $this->runEvent($event);
        } else {
            $this->log->debug(sprintf('<info>Skipping command (has already run on another server):</info> #%s', $this->uuid));
        }
    }
    /**
     * Run the given event.
     *
     * @param Event $event
     * @return void
     * @throws \Throwable
     */
    protected function runEvent($event)
    {
        $this->dispatcher->dispatch(new ScheduledTaskStarting($event));
        $this->log->debug(sprintf('Running scheduled command: #%s [%s]', $this->uuid, $event->getSummaryForDisplay()));
        $start = microtime(\true);
        try {
            $event->run($this->laravel);
            $this->dispatcher->dispatch(new ScheduledTaskFinished($event, round(microtime(\true) - $start, 2)));
            $this->log->debug(sprintf('Finished running command #%s in %ss', $this->uuid, round(microtime(\true) - $start, 2)));
            $this->eventsRan = \true;
        } catch (\Throwable $e) {
            if (class_exists(ScheduledTaskFailed::class)) {
                $this->dispatcher->dispatch(new ScheduledTaskFailed($event, $e));
            }
            $this->handler->report($e);
        }
    }
    /**
     * Execute the console command.
     *
     * @param Schedule $schedule
     * @param Dispatcher $dispatcher
     * @param ExceptionHandler $handler
     * @return void
     * @throws \Throwable
     */
    public function handle(Schedule $schedule, Dispatcher $dispatcher, ExceptionHandler $handler)
    {
        $this->schedule = $schedule;
        $this->dispatcher = $dispatcher;
        $this->handler = $handler;
        $this->log->debug(sprintf('Starting schedule run: #%s', $this->uuid));
        /**
         * @var Event[] $events
         */
        $events = $this->schedule->dueEvents($this->laravel);
        foreach ($events as $event) {
            if (!$event->filtersPass($this->laravel)) {
                $this->dispatcher->dispatch(new ScheduledTaskSkipped($event));
                continue;
            }
            if ($event->onOneServer) {
                $this->runSingleServerEvent($event);
            } else {
                $this->runEvent($event);
            }
            $this->eventsRan = \true;
        }
        if (!$this->eventsRan) {
            $this->log->debug('No scheduled commands are ready to run.');
        }
    }
}
