<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Queue\WordPress;

use Modular\ConnectorDependencies\Carbon\Carbon;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ClearableQueue;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\Queue as QueueContract;
use Modular\ConnectorDependencies\Illuminate\Queue\Jobs\DatabaseJobRecord;
use Modular\ConnectorDependencies\Illuminate\Queue\Queue;
use Modular\ConnectorDependencies\Illuminate\Support\Str;
class WordpressQueue extends Queue implements QueueContract, ClearableQueue
{
    /**
     * The database connection instance.
     *
     * @var \Illuminate\Database\Connection
     */
    protected $database;
    /**
     * The database table that holds the jobs.
     *
     * @var string
     */
    protected $table;
    /**
     * The queue table prefix.
     *
     * @var string
     */
    protected $prefix;
    /**
     * The name of the default queue.
     *
     * @var string
     */
    protected $default;
    /**
     * The expiration time of a job.
     *
     * @var int|null
     */
    protected $retryAfter = 60;
    /**
     * Create a new database queue instance.
     *
     * @param \wpdb $database
     * @param string $table
     * @param string $prefix
     * @param string $default
     * @param int $retryAfter
     */
    public function __construct(\wpdb $database, string $table, string $prefix = '', string $default = 'default', int $retryAfter = 60)
    {
        $this->database = $database;
        $this->table = $table;
        $this->prefix = $prefix;
        $this->default = $default;
        $this->retryAfter = $retryAfter;
    }
    /**
     * Get the table definition based on multisite or single site.
     *
     * @return array
     */
    public function getTableDefinition()
    {
        // Determine table and column names based on multisite or single site
        $table = $this->database->options;
        $keyColumn = 'option_id';
        $nameColumn = 'option_name';
        $valueColumn = 'option_value';
        return [$table, $keyColumn, $nameColumn, $valueColumn];
    }
    public function getJobLikeQuery(string $queue)
    {
        // Get the table definition
        [$table, $keyColumn, $nameColumn, $valueColumn] = $this->getTableDefinition();
        // Get the identifier for the queue
        $identifier = $this->getIdentifier($queue);
        // Prepare the patterns to exclude
        $patterns = [$this->database->esc_like($identifier) . '%_reserved_at', $this->database->esc_like($identifier) . '%_attempts', $this->database->esc_like($identifier) . '%_available_at'];
        // Build the WHERE clause
        $whereClause = "job.{$nameColumn} LIKE %s";
        foreach ($patterns as $excludePattern) {
            $whereClause .= " AND job.{$nameColumn} NOT LIKE %s";
        }
        $patterns = array_merge([$this->database->esc_like($identifier) . '%' . '_'], $patterns);
        return [$whereClause, $patterns];
    }
    /**
     * Get the size of the queue.
     *
     * @param string|null $queue
     * @return int
     */
    public function size($queue = null)
    {
        // Get the table definition
        [$table] = $this->getTableDefinition();
        [$whereClause, $excludePatterns] = $this->getJobLikeQuery($queue);
        // Prepare the SQL query to count the jobs
        $sql = $this->database->prepare("SELECT COUNT(*) FROM {$table} as job WHERE {$whereClause}", $excludePatterns);
        // Get the count
        return (int) $this->database->get_var($sql);
    }
    /**
     * @param $job
     * @param $data
     * @param $queue
     * @return mixed
     */
    public function push($job, $data = '', $queue = null)
    {
        // Force to dispatch the schedule
        \Modular\ConnectorDependencies\app()->forceDispatchScheduleRun();
        $queue = $this->getQueue($queue);
        return $this->enqueueUsing($job, $this->createPayload($job, $queue, $data), $queue, null, fn($payload, $queue) => $this->pushToDatabase($queue, $payload));
    }
    /**
     * Push a raw payload onto the queue.
     *
     * @param string $payload
     * @param string|null $queue
     * @param array $options
     * @return mixed
     */
    public function pushRaw($payload, $queue = null, array $options = [])
    {
        return $this->pushToDatabase($queue, $payload);
    }
    /**
     * Push a new job onto the queue after a delay.
     *
     * @param \DateTimeInterface|\DateInterval|int $delay
     * @param string $job
     * @param mixed $data
     * @param string|null $queue
     * @return void
     */
    public function later($delay, $job, $data = '', $queue = null)
    {
        return $this->enqueueUsing($job, $this->createPayload($job, $this->getQueue($queue), $data), $queue, $delay, function ($payload, $queue, $delay) {
            return $this->pushToDatabase($queue, $payload, $delay);
        });
    }
    /**
     * Release a reserved job back onto the queue.
     *
     * @param string $queue
     * @param \Illuminate\Queue\Jobs\DatabaseJobRecord $job
     * @param int $delay
     * @return mixed
     */
    public function release($queue, $job, $delay)
    {
        return $this->pushToDatabase($queue, $job->payload, $delay, $job->attempts);
    }
    /**
     * Push a raw payload to the database with a given delay of (n) seconds.
     *
     * @param string|null $queue
     * @param string $payload
     * @return mixed
     */
    protected function pushToDatabase($queue, $payload, $delay = 0, $attempts = 0)
    {
        $key = $this->getKey($queue);
        $KeyAttempts = $key . '_attempts';
        $KeyCreatedAt = $key . '_created_at';
        $key = update_option($key, $payload);
        if ($key) {
            update_option($KeyAttempts, $attempts);
            update_option($KeyCreatedAt, Carbon::now()->timestamp);
            if ($delay) {
                $availableAt = $this->availableAt($delay);
                $keyAvailableAt = $key . '_available_at';
                update_option($keyAvailableAt, $availableAt);
            }
        }
    }
    /**
     * Pop the next job off of the queue.
     *
     * @param string|null $queue
     * @return \Illuminate\Contracts\Queue\Job|null
     *
     * @throws \Throwable
     */
    public function pop($queue = null)
    {
        $queue = $this->getQueue($queue);
        if ($job = $this->getNextAvailableJob($queue)) {
            return $this->marshalJob($queue, $job);
        }
    }
    /**
     * Marshal the reserved job into a DatabaseJob instance.
     *
     * @param string $queue
     * @param \Illuminate\Queue\Jobs\DatabaseJobRecord $job
     * @return WordpressJob
     */
    protected function marshalJob($queue, $job)
    {
        $job = $this->markJobAsReserved($job);
        return new WordpressJob($this->container, $this, $job, $this->connectionName, $queue);
    }
    /**
     * Get the next available job for the queue.
     *
     * @param string|null $queue
     * @return \Illuminate\Queue\Jobs\DatabaseJobRecord|null
     */
    public function getNextAvailableJob(string $queue)
    {
        // Get the table definition
        [$table, $keyColumn, $nameColumn, $valueColumn] = $this->getTableDefinition();
        [$whereClause, $excludePatterns] = $this->getJobLikeQuery($queue);
        // Current time and expiration time
        $currentTime = Carbon::now()->timestamp;
        $expirationTime = $currentTime - $this->retryAfter;
        // Prepare the SQL query to find the next available job
        $sql = $this->database->prepare("\n        SELECT job.{$keyColumn}, job.{$nameColumn}, job.{$valueColumn}, reserved.{$valueColumn} AS reserved_at, availabled.{$valueColumn} AS available_at, attempts.{$valueColumn} AS attempts\n        FROM {$table} AS job\n        LEFT JOIN {$table} AS availabled ON availabled.{$nameColumn} = CONCAT(job.{$nameColumn}, '_available_at')\n        LEFT JOIN {$table} AS reserved ON reserved.{$nameColumn} = CONCAT(job.{$nameColumn}, '_reserved_at')\n        LEFT JOIN {$table} AS attempts ON attempts.{$nameColumn} = CONCAT(job.{$nameColumn}, '_attempts')\n        WHERE {$whereClause}\n        AND (\n            -- isAvailable\n            (\n                reserved.{$valueColumn} IS NULL\n                AND\n                (availabled.{$valueColumn} IS NULL OR CAST(availabled.{$valueColumn} AS UNSIGNED) <= %d)\n            )\n            -- isReservedButExpired\n            OR reserved.{$valueColumn} IS NOT NULL AND CAST(reserved.{$valueColumn} AS UNSIGNED) <= %d\n        )\n        ORDER BY job.{$keyColumn} ASC\n        LIMIT 1\n        ", array_merge($excludePatterns, [$currentTime, $expirationTime]));
        // Fetch the next available job
        $item = $this->database->get_row($sql);
        // Check if a job was found
        if (!$item) {
            return null;
        }
        // Decode the job data
        $job = ['id' => $item->{$nameColumn}, 'queue' => $queue, 'payload' => $item->{$valueColumn}, 'attempts' => $item->attempts, 'reserved_at' => $item->reserved_at, 'available_at' => $item->available_at];
        // Return the job data as a DatabaseJobRecord instance
        return new DatabaseJobRecord((object) $job);
    }
    /**
     * Mark the given job ID as reserved.
     *
     * @param \Illuminate\Queue\Jobs\DatabaseJobRecord $job
     * @return \Illuminate\Queue\Jobs\DatabaseJobRecord
     */
    protected function markJobAsReserved(?DatabaseJobRecord $job)
    {
        // Update the reserved_at option
        $reservedAtOptionName = $job->id . '_reserved_at';
        update_option($reservedAtOptionName, $job->touch(), 'off');
        $attemptsOptionName = $job->id . '_attempts';
        update_option($attemptsOptionName, $job->increment(), 'off');
        return $job;
    }
    /**
     * Delete a reserved job from the queue.
     *
     * @param string $queue
     * @param string $id
     * @return void
     *
     * @throws \Throwable
     */
    public function deleteReserved($queue, $id)
    {
        delete_option($id);
        delete_option($id . '_reserved_at');
        delete_option($id . '_attempts');
        delete_option($id . '_available_at');
        delete_option($id . '_created_at');
    }
    /**
     * Delete a reserved job from the reserved queue and release it.
     *
     * @param string $queue
     * @param WordpressJob $job
     * @param int $delay
     * @return void
     * @throws \Throwable
     */
    public function deleteAndRelease($queue, $job, $delay)
    {
        $this->deleteReserved($job->getJobId());
        $this->release($queue, $job->getJobRecord(), $delay);
    }
    /**
     * Delete all of the jobs from the queue.
     *
     * @param string $queue
     * @return int
     */
    public function clear($queue)
    {
        // Get the table definition
        [$table, $keyColumn, $nameColumn, $valueColumn] = $this->getTableDefinition();
        // Get the identifier for the queue
        $identifier = $this->getIdentifier($queue);
        // Prepare the LIKE pattern to match all related options
        $likePattern = $this->database->esc_like($identifier) . '%';
        // Prepare the SQL query to delete the jobs
        $sql = $this->database->prepare("DELETE FROM {$table} WHERE {$nameColumn} LIKE %s", $likePattern);
        // Execute the query
        return $this->database->query($sql);
    }
    /**
     * Delete old pending jobs from the queue.
     *
     * @param string $queue
     * @param int $maxAge
     * @return void
     */
    public function clearOldPendingJobs($queue, $maxAge = 60 * 60 * 24)
    {
        // Get the table definition
        [$table, $keyColumn, $nameColumn, $valueColumn] = $this->getTableDefinition();
        $identifier = $this->getIdentifier($queue);
        $this->database->query($this->database->prepare("\n                    DELETE job FROM {$table} AS job\n                    INNER JOIN (\n                        SELECT job.option_name AS prefix\n                        FROM {$table} AS job\n                        INNER JOIN {$table} AS created\n                            ON created.option_name = CONCAT(job.option_name, '_created_at')\n                        WHERE CAST(created.option_value AS UNSIGNED) <= %d\n                    ) AS expired\n                     ON job.option_name = CONCAT(expired.prefix, '_created_at')\n                        OR job.option_name = CONCAT(expired.prefix, '_reserved_at')\n                        OR job.option_name = CONCAT(expired.prefix, '_attempts')\n                        OR job.option_name = CONCAT(expired.prefix, '_available_at')\n                        OR job.option_name = expired.prefix\n                    WHERE job.{$nameColumn} LIKE %s\n                ", Carbon::now()->timestamp - $maxAge, $this->database->esc_like($identifier) . '%'));
    }
    /**
     * Get identifier for queue
     *
     * @param string $queue
     * @param string|null $suffix
     * @return string
     */
    public function getIdentifier(string $queue, string $suffix = null)
    {
        return $this->prefix . '_' . $queue . ($suffix ?: '');
    }
    /**
     * @param string $queue
     * @return string
     */
    public function getKey(string $queue)
    {
        $unique = md5(Str::uuid()->toString());
        $prepend = $this->getIdentifier($queue, '_');
        return \substr($prepend . $unique, 0, 64);
    }
    /**
     * Get the queue or return the default.
     *
     * @param string|null $queue
     * @return string
     */
    public function getQueue($queue)
    {
        return $queue ?: $this->default;
    }
}
