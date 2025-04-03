<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Queue\Database;

use Modular\ConnectorDependencies\Illuminate\Queue\DatabaseQueue as IlluminateDatabaseQueue;
class DatabaseQueue extends IlluminateDatabaseQueue
{
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
}
