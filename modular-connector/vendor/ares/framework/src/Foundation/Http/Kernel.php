<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Http;

use Modular\ConnectorDependencies\Ares\Framework\Foundation\Bootloader;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Console\Scheduling\Schedule;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Console\Scheduling\ScheduleRunCommand;
use Modular\ConnectorDependencies\Illuminate\Contracts\Debug\ExceptionHandler;
use Modular\ConnectorDependencies\Illuminate\Contracts\Foundation\Application;
use Modular\ConnectorDependencies\Illuminate\Foundation\Http\Kernel as FoundationKernel;
use Modular\ConnectorDependencies\Illuminate\Routing\Router;
class Kernel extends FoundationKernel
{
    /**
     * Indicates if the Closure commands have been loaded.
     *
     * @var bool
     */
    protected $commandsLoaded = \false;
    /**
     * The bootstrap classes for the application.
     *
     * @var string[]
     */
    protected $bootstrappers = [\Modular\ConnectorDependencies\Illuminate\Foundation\Bootstrap\LoadConfiguration::class, \Modular\ConnectorDependencies\Ares\Framework\Foundation\Bootstrap\HandleExceptions::class, \Modular\ConnectorDependencies\Illuminate\Foundation\Bootstrap\RegisterFacades::class, \Modular\ConnectorDependencies\Illuminate\Foundation\Bootstrap\RegisterProviders::class, \Modular\ConnectorDependencies\Illuminate\Foundation\Bootstrap\BootProviders::class];
    /**
     * Create a new HTTP kernel instance.
     *
     * @param \Illuminate\Contracts\Foundation\Application $app
     * @param \Illuminate\Routing\Router $router
     * @return void
     */
    public function __construct(Application $app, Router $router)
    {
        parent::__construct($app, $router);
        $this->app->booted(function () {
            $this->defineConsoleSchedule();
            $this->runSchedule();
        });
    }
    /**
     * Get the timezone that should be used by default for scheduled events.
     *
     * @return \DateTimeZone|string|null
     */
    protected function scheduleTimezone()
    {
        $config = $this->app['config'];
        return $config->get('app.schedule_timezone', $config->get('app.timezone'));
    }
    /**
     * Define the application's command schedule.
     *
     * @return void
     */
    protected function defineConsoleSchedule()
    {
        $this->app->singleton(Schedule::class, function ($app) {
            return \Modular\ConnectorDependencies\tap(new Schedule($this->scheduleTimezone()), function ($schedule) {
                $this->schedule($schedule);
            });
        });
    }
    /**
     * @return void
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     *
     */
    public function runSchedule()
    {
        $hook = $this->app->getScheduleHook();
        add_action($hook, function () {
            // When running from cron, we need to boot the bootloader.
            if (HttpUtils::isCron()) {
                $bootloader = Bootloader::getInstance();
                $bootloader->bootCron();
            }
            $schedule = \Modular\ConnectorDependencies\app()->make(Schedule::class);
            $dispatcher = \Modular\ConnectorDependencies\app()->make('events');
            $exceptionHandler = \Modular\ConnectorDependencies\app()->make(ExceptionHandler::class);
            \Modular\ConnectorDependencies\app()->make(ScheduleRunCommand::class)->handle($schedule, $dispatcher, $exceptionHandler);
        });
        add_filter('cron_schedules', function ($schedules) {
            $schedules['ares_every_minute'] = ['interval' => \MINUTE_IN_SECONDS, 'display' => __('Every minute')];
            return $schedules;
        });
        if (!wp_next_scheduled($hook)) {
            wp_schedule_event(time(), 'ares_every_minute', $hook);
        }
        $ajaxAction = function () use ($hook) {
            $this->app->make('log')->debug('Running schedule hook: ' . $hook . ' via AJAX');
            do_action($hook);
        };
        add_action('wp_ajax_' . $hook, $ajaxAction);
        add_action('wp_ajax_nopriv_' . $hook, $ajaxAction);
    }
    /**
     * Define the application's command schedule.
     *
     * @param Schedule $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        //
    }
    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
    }
    /**
     * Bootstrap the application for artisan commands.
     *
     * @return void
     */
    public function bootstrap()
    {
        parent::bootstrap();
        if (!$this->commandsLoaded) {
            $this->commands();
            $this->commandsLoaded = \true;
        }
    }
}
