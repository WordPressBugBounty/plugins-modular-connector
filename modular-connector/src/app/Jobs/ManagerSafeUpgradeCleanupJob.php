<?php

namespace Modular\Connector\Jobs;

use Modular\Connector\Events\ManagerSafeUpgradeCleanedUp;
use Modular\Connector\Facades\SafeUpgrade;
use Modular\ConnectorDependencies\Illuminate\Bus\Queueable;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldQueue;
use Modular\ConnectorDependencies\Illuminate\Foundation\Bus\Dispatchable;
use Modular\ConnectorDependencies\Illuminate\Support\InteractsWithTime;
use function Modular\ConnectorDependencies\data_get;
use function Modular\ConnectorDependencies\event;

class ManagerSafeUpgradeCleanupJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable;
    use Queueable;
    use InteractsWithTime;

    /**
     * @var string
     */
    protected string $mrid;

    /**
     * @var mixed
     */
    protected $payload;

    /**
     * @var string
     */
    protected string $type;

    /**
     * The number of seconds after which the job's unique lock will be released.
     *
     * @var int
     */
    public $uniqueFor = 2 * 3600; // 2 hour

    /**
     * @param string $mrid
     * @param $payload
     * @param string $type
     */
    public function __construct(string $mrid, $payload, string $type)
    {
        $this->mrid = $mrid;
        $this->payload = $payload;
        $this->type = $type;
    }

    public function handle(): void
    {
        $payload = $this->payload;

        $items = data_get($payload, 'items', []);

        $result = SafeUpgrade::bulkDelete($this->type, $items);

        event(new ManagerSafeUpgradeCleanedUp($this->mrid, $result));
    }
}
