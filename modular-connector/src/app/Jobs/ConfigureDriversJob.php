<?php

namespace Modular\Connector\Jobs;

use Modular\ConnectorDependencies\Illuminate\Bus\Queueable;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldBeUnique;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldQueue;
use Modular\ConnectorDependencies\Illuminate\Foundation\Bus\Dispatchable;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Cache;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Queue;

class ConfigureDriversJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use Queueable;

    /**
     * @return void
     */
    protected function configureCacheDriver()
    {
        $driver = 'file';

        try {
            $tmpDriver = 'database';

            // We need to check if the cache database driver is working
            Cache::driver($tmpDriver)->has('cache.driver.test');

            $driver = $tmpDriver;
        } catch (\Throwable $e) {
            // Silence is golden
            Log::error($e);
        }

        Cache::driver('wordpress')->forever('cache.default', $driver);
    }

    /**
     * @return void
     */
    protected function configureQueueDriver()
    {
        $driver = 'wordpress';

        try {
            $tmpDriver = 'database';

            // We need to check if the queue driver is working
            Queue::connection($tmpDriver)->size();

            $driver = $tmpDriver;
        } catch (\Throwable $e) {
            // Silence is golden
            Log::error($e);
        }

        Cache::driver('wordpress')->forever('queue.default', $driver);
    }

    public function handle(): void
    {
        Log::debug('Configuring cache and queue drivers');

        $this->configureCacheDriver();
        $this->configureQueueDriver();
    }

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return MODULAR_CONNECTOR_VERSION;
    }
}
