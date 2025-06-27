<?php

namespace Modular\Connector\Http;

use Modular\Connector\Backups\Console\AutoCleanUpCommand;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Console\Scheduling\Schedule;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Http\HttpUtils;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Http\Kernel as HttpKernel;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Queue\WorkCommand;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Queue;

class Kernel extends HttpKernel
{
    /**
     * The application's route middleware groups.
     *
     * @var array
     */
    protected $middlewareGroups = [
        'api' => [],
    ];

    /**
     * The application's route middleware.
     *
     * These middleware may be assigned to groups or used individually.
     *
     * @var array
     */
    protected $routeMiddleware = [
        'auth' => \Modular\Connector\Http\Middleware\Authenticate::class,
    ];

    /**
     * @param Schedule $schedule
     * @return void
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    protected function schedule(Schedule $schedule)
    {
        Log::debug('Scheduling commands for Modular Connector');

        $schedule->command(WorkCommand::class, [
            '--connection' => 'wordpress',
            '--queue' => 'default',
            '--stop-when-empty' => 1,
            '--timeout' => 600,
            '--memory' => HttpUtils::maxMemoryLimit(true),
            '--max-time' => 30,
            '--max-jobs' => 4,
        ])
            ->withoutOverlapping(10)
            ->everyMinute()
            ->skip(function () {
                return $this->app->make('config')->get('queue.default') === 'wordpress' ||
                    Queue::connection('wordpress')->size('default') === 0;
            });

        $schedule->command(WorkCommand::class, [
            '--queue' => 'default',
            '--stop-when-empty' => 1,
            '--timeout' => 600,
            '--memory' => HttpUtils::maxMemoryLimit(true),
            '--max-time' => 30,
            '--max-jobs' => 4,
        ])
            ->withoutOverlapping(10) // 15 min
            ->everyMinute()
            ->skip(fn() => Queue::connection()->size('default') === 0);

        $schedule->command(WorkCommand::class, [
            '--queue' => 'backups',
            '--stop-when-empty' => 1,
            '--timeout' => 600,
            '--memory' => HttpUtils::maxMemoryLimit(true),
            '--max-time' => 30,
            '--max-jobs' => 4,
        ])
            ->withoutOverlapping(10) // 10 min
            ->everyMinute()
            ->skip(fn() => Queue::connection()->size('backups') === 0);

        $schedule->command(WorkCommand::class, [
            '--queue' => 'optimizations',
            '--stop-when-empty' => 1,
            '--timeout' => 600,
            '--memory' => HttpUtils::maxMemoryLimit(true),
            '--max-time' => 30,
            '--max-jobs' => 4,
        ])
            ->withoutOverlapping(10) // 10 min
            ->everyMinute()
            ->skip(fn() => Queue::connection()->size('optimizations') === 0);

        $schedule->command(AutoCleanUpCommand::class, [
            '--max-files' => 10,
            '--max-age' => 1,
        ])
            ->withoutOverlapping(60) // 1 hour
            ->everyMinute();
    }
}
