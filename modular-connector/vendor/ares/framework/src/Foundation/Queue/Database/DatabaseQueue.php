<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Queue\Database;

use Modular\ConnectorDependencies\Illuminate\Queue\DatabaseQueue as IlluminateDatabaseQueue;
class DatabaseQueue extends IlluminateDatabaseQueue
{
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
        if ($this->database->table($this->table)->lockForUpdate()->find($id)) {
            $this->database->table($this->table)->where('id', $id)->delete();
        }
    }
    /**
     * Delete a reserved job from the reserved queue and release it.
     *
     * @param string $queue
     * @param \Illuminate\Queue\Jobs\DatabaseJob $job
     * @param int $delay
     * @return void
     */
    public function deleteAndRelease($queue, $job, $delay)
    {
        if ($this->database->table($this->table)->lockForUpdate()->find($job->getJobId())) {
            $this->database->table($this->table)->where('id', $job->getJobId())->delete();
        }
        $this->release($queue, $job->getJobRecord(), $delay);
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
        return parent::push($job, $data, $queue);
    }
    /**
     * Delete old pending jobs from the queue.
     *
     * @param int $maxAge
     * @return int
     */
    public function clearOldPendingJobs($queue, $maxAge = 60 * 60 * 24)
    {
        return $this->database->table($this->table)->where('created_at', '<', time() - $maxAge)->whereNull('reserved_at')->delete();
    }
}
