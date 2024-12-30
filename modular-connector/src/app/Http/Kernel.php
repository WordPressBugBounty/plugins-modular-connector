<?php

namespace Modular\Connector\Http;

use Modular\ConnectorDependencies\Ares\Framework\Foundation\Console\Scheduling\Schedule;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Http\HttpUtils;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Http\Kernel as HttpKernel;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Queue\WorkCommand;

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
        $schedule->command(WorkCommand::class, [
            '--queue' => 'default',
            '--stop-when-empty' => 1,
            '--timeout' => 600,
            '--memory' => HttpUtils::maxMemoryLimit(true),
            '--max-time' => 30,
            '--max-jobs' => 4,
        ])
            ->withoutOverlapping(15) // 15 min
            ->everyMinute();

        $schedule->command(WorkCommand::class, [
            '--queue' => 'backups',
            '--stop-when-empty' => 1,
            '--timeout' => 600,
            '--memory' => HttpUtils::maxMemoryLimit(true),
            '--max-time' => 30,
            '--max-jobs' => 4,
        ])
            ->withoutOverlapping(15) // 15 min
            ->everyMinute();
    }
}
