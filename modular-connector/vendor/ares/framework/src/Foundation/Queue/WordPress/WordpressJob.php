<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Queue\WordPress;

use Modular\ConnectorDependencies\Illuminate\Container\Container;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\Job as JobContract;
use Modular\ConnectorDependencies\Illuminate\Queue\Jobs\Job;
class WordpressJob extends Job implements JobContract
{
    /**
     * The database queue instance.
     *
     * @var WordpressQueue
     */
    protected $database;
    /**
     * The database job payload.
     *
     * @var \stdClass
     */
    protected $job;
    /**
     * Create a new job instance.
     *
     * @param \Illuminate\Container\Container $container
     * @param WordpressQueue $database
     * @param \stdClass $job
     * @param string $connectionName
     * @param string $queue
     * @return void
     */
    public function __construct(Container $container, WordpressQueue $database, $job, $connectionName, $queue)
    {
        $this->job = $job;
        $this->queue = $queue;
        $this->database = $database;
        $this->container = $container;
        $this->connectionName = $connectionName;
    }
    /**
     * Release the job back into the queue.
     *
     * @param int $delay
     * @return void
     */
    public function release($delay = 0)
    {
        parent::release($delay);
        $this->database->deleteAndRelease($this->queue, $this, $delay);
    }
    /**
     * Delete the job from the queue.
     *
     * @return void
     */
    public function delete()
    {
        parent::delete();
        $this->database->deleteReserved($this->queue, $this->job->id);
    }
    /**
     * Get the number of times the job has been attempted.
     *
     * @return int
     */
    public function attempts()
    {
        return (int) $this->job->attempts;
    }
    /**
     * Get the job identifier.
     *
     * @return string
     */
    public function getJobId()
    {
        return $this->job->id;
    }
    /**
     * Get the raw body string for the job.
     *
     * @return string
     */
    public function getRawBody()
    {
        return $this->job->payload;
    }
    /**
     * Get the database job record.
     *
     * @return \Illuminate\Queue\Jobs\DatabaseJobRecord
     */
    public function getJobRecord()
    {
        return $this->job;
    }
    /**
     * Process an exception that caused the job to fail.
     *
     * @param \Throwable|null $e
     * @return void
     */
    protected function failed($e)
    {
        // TODO: Implement failed() method.
    }
}
