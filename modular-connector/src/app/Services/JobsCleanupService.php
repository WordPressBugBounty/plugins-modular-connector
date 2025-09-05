<?php

namespace Modular\Connector\Services;

use Modular\ConnectorDependencies\Carbon\Carbon;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Cache;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use function Modular\ConnectorDependencies\app;
use function Modular\ConnectorDependencies\request;

class JobsCleanupService
{
    /**
     * The cache key used to store the last cleanup timestamp
     */
    private const LAST_CLEANUP_CACHE_KEY = '_modular_last_jobs_cleanup';

    /**
     * The lock timeout in seconds
     */
    private const LOCK_TIMEOUT = 60;

    /**
     * The minimum number of seconds between cleanup attempts
     *
     * Default is 1 day
     */
    private const CLEANUP_MIN_INTERVAL = 86400;

    /**
     * The number of seconds after which old jobs should be cleaned up
     *
     * Default is 1 day
     */
    private const CLEANUP_JOBS_OLDER_THAN_SECONDS = 86400;

    /**
     * Deletes all old pending jobs from the queue.
     *
     * @param string $queueName
     * @return void
     */
    public function clearOldJobsFromQueue(string $queueName)
    {
        $connections = array_keys(app('config')->get('queue.connections'));

        foreach ($connections as $connection) {
            $queue = app('queue')->connection($connection);

            Log::debug('Clearing old jobs from queue', [
                'queue' => $queueName,
                'connection' => $connection,
            ]);

            if (method_exists($queue, 'clearOldPendingJobs')) {
                $queue->clearOldPendingJobs(
                    $queueName,
                    self::CLEANUP_JOBS_OLDER_THAN_SECONDS
                );
            } else {
                Log::debug('Queue connection does not support clearOldPendingJobs', [
                    'queue' => $queueName,
                    'connection' => $connection,
                ]);
            }
        }
    }

    /**
     * Attempt to clean up old pending jobs
     *
     * @return bool
     */
    public function attemptCleanup(): bool
    {
        // Get last cleanup timestamp from cache
        $lastCleanup = Cache::get(self::LAST_CLEANUP_CACHE_KEY, 0);

        Log::debug('Last cleanup timestamp', ['last_cleanup' => $lastCleanup, 'url' => request()->fullUrl()]);

        // Current timestamp using Carbon
        $nowTimestamp = Carbon::now()->timestamp;

        // If minimum interval has not passed, skip cleanup
        if ($nowTimestamp - $lastCleanup < self::CLEANUP_MIN_INTERVAL) {
            return false;
        }

        // Acquire lock to prevent concurrent cleanups
        $lock = Cache::lock('_modular_jobs_cleanup_lock', self::LOCK_TIMEOUT);
        if (!$lock->get()) {
            return false;
        }

        try {
            // Clear default queue
            try {
                Log::debug('Clearing old jobs from default queue', ['queue' => 'default']);
                $this->clearOldJobsFromQueue('default');
            } catch (\Throwable $e) {
                Log::error('Failed to clear default queue', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }

            // Clear backups queue
            try {
                Log::debug('Clearing old jobs from backups queue', ['queue' => 'backups']);
                $this->clearOldJobsFromQueue('backups');
            } catch (\Throwable $e) {
                Log::error('Failed to clear backups queue', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }

            // Clear optimizations queue
            try {
                Log::debug('Clearing old jobs from optimizations queue', ['queue' => 'optimizations']);
                $this->clearOldJobsFromQueue('optimizations');
            } catch (\Throwable $e) {
                Log::error('Failed to clear optimizations queue', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }

            // Update last cleanup timestamp
            Cache::put(
                self::LAST_CLEANUP_CACHE_KEY,
                Carbon::now()->timestamp
            );

            return true;
        } catch (\Throwable $e) {
            Log::error('Failed to cleanup pending jobs', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        } finally {
            $lock->release();
        }
    }
}
