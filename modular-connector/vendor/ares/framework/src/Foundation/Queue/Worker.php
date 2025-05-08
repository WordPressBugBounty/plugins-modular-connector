<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Queue;

use Modular\ConnectorDependencies\Illuminate\Queue\Worker as IlluminateWorker;
use Modular\ConnectorDependencies\Illuminate\Queue\WorkerOptions;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
class Worker extends IlluminateWorker
{
    /**
     * Determine if "async" signals are supported.
     *
     * @return bool
     */
    protected function supportsAsyncSignals()
    {
        $functions = ['pcntl_signal', 'pcntl_alarm', 'pcntl_async_signals', 'posix_kill'];
        foreach ($functions as $function) {
            if (!function_exists($function)) {
                return \false;
            }
        }
        $disabledFunctions = explode(',', @ini_get('disable_functions'));
        foreach ($functions as $function) {
            if (\in_array($function, $disabledFunctions)) {
                return \false;
            }
        }
        return \true;
    }
    /**
     * Determine the exit code to stop the process if necessary.
     *
     * @param \Illuminate\Queue\WorkerOptions $options
     * @param int $lastRestart
     * @param int $startTime
     * @param int $jobsProcessed
     * @param mixed $job
     * @return int|null
     */
    protected function stopIfNecessary(WorkerOptions $options, $lastRestart, $startTime = 0, $jobsProcessed = 0, $job = null)
    {
        if ($this->shouldQuit) {
            if (class_exists(Log::class)) {
                Log::debug('Worker should quit');
            }
            return static::EXIT_SUCCESS;
        } elseif ($this->memoryExceeded($options->memory)) {
            if (class_exists(Log::class)) {
                Log::debug('Worker memory exceeded');
            }
            \Modular\ConnectorDependencies\app()->forceDispatchScheduleRun();
            return static::EXIT_MEMORY_LIMIT;
        } elseif ($this->queueShouldRestart($lastRestart)) {
            if (class_exists(Log::class)) {
                Log::debug('Worker queue should restart');
            }
            \Modular\ConnectorDependencies\app()->forceDispatchScheduleRun();
            return static::EXIT_SUCCESS;
        } elseif ($options->stopWhenEmpty && is_null($job)) {
            if (class_exists(Log::class)) {
                Log::debug('Worker stop when empty');
            }
            return static::EXIT_SUCCESS;
        } elseif ($options->maxTime && hrtime(\true) / 1000000000.0 - $startTime >= $options->maxTime) {
            if (class_exists(Log::class)) {
                Log::debug('Worker max time exceeded');
            }
            \Modular\ConnectorDependencies\app()->forceDispatchScheduleRun();
            return static::EXIT_SUCCESS;
        } elseif ($options->maxJobs && $jobsProcessed >= $options->maxJobs) {
            if (class_exists(Log::class)) {
                Log::debug('Worker max jobs exceeded');
            }
            \Modular\ConnectorDependencies\app()->forceDispatchScheduleRun();
            return static::EXIT_SUCCESS;
        }
    }
}
