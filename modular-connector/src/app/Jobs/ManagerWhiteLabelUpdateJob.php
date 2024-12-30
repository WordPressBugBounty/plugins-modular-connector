<?php

namespace Modular\Connector\Jobs;

use Modular\Connector\Facades\WhiteLabel;
use Modular\ConnectorDependencies\Illuminate\Bus\Queueable;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldBeUnique;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldQueue;
use Modular\ConnectorDependencies\Illuminate\Foundation\Bus\Dispatchable;

class ManagerWhiteLabelUpdateJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use Queueable;

    /**
     * @var array
     */
    protected $data;

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
     * @param $data
     */
    public function __construct(string $mrid, $data)
    {
        $this->mrid = $mrid;
        $this->data = $data;
    }

    /**
     * @return void
     */
    public function handle(): void
    {
        WhiteLabel::update($this->data);
    }

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return $this->mrid;
    }
}
