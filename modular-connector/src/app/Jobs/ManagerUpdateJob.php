<?php

namespace Modular\Connector\Jobs;

use Modular\Connector\Events\ManagerItemsUpdated;
use Modular\Connector\Facades\Manager;
use function Modular\ConnectorDependencies\event;

class ManagerUpdateJob
{
    /**
     * @var string
     */
    protected string $mrid;

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
