<?php

namespace Modular\Connector\Jobs;

use Modular\Connector\Events\ManagerItemsUpdated;
use Modular\Connector\Facades\Manager;
use Modular\ConnectorDependencies\Illuminate\Bus\Queueable;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldQueue;
use Modular\ConnectorDependencies\Illuminate\Foundation\Bus\Dispatchable;
use function Modular\ConnectorDependencies\event;

class ManagerUpdateJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    /**
     * @var string
     */
    protected string $mrid;

    /**
     * The number of seconds after which the job's unique lock will be released.
     *
     * @var int
     */
    public $uniqueFor = 2 * 3600; // 2 hour

    /**
     * @param string $mrid
     */
    public function __construct(string $mrid)
    {
        $this->mrid = $mrid;
    }

    public function handle(): void
    {
        $items = Manager::update();

        event(new ManagerItemsUpdated($this->mrid, $items));
    }

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return $this->mrid;
    }
}
