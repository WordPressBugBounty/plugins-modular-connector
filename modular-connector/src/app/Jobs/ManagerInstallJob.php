<?php

namespace Modular\Connector\Jobs;

use Modular\Connector\Events\ManagerItemsInstalled;
use Modular\Connector\Facades\Manager;
use Modular\Connector\Services\Manager\ManagerPlugin;
use Modular\Connector\Services\Manager\ManagerTheme;
use Modular\ConnectorDependencies\Illuminate\Bus\Queueable;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldQueue;
use Modular\ConnectorDependencies\Illuminate\Foundation\Bus\Dispatchable;
use function Modular\ConnectorDependencies\dispatch;
use function Modular\ConnectorDependencies\event;

class ManagerInstallJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    /**
     * @var string
     */
    protected string $mrid;

    /**
     * @var mixed
     */
    protected $payload;

    /**
     * The number of seconds after which the job's unique lock will be released.
     *
     * @var int
     */
    public $uniqueFor = 2 * 3600; // 2 hour

    /**
     * @param string $mrid
     * @param $payload
     */
    public function __construct(string $mrid, $payload)
    {
        $this->mrid = $mrid;
        $this->payload = $payload;
    }

    public function handle(): void
    {
        $payload = $this->payload;

        if ($payload->type === ManagerTheme::THEME) {
            $result = Manager::driver(ManagerTheme::THEME)->install($payload->downloadLink, $payload->overwrite);
        } else {
            $result = Manager::driver(ManagerPlugin::PLUGIN)->install($payload->downloadLink, $payload->overwrite);
        }

        $result['name'] = $payload->name ?? 'unknown';

        if ($payload->activate && $result['success'] === true) {
            $key = $payload->type . 's';

            $payload = (object)[
                $key => (object)[$result['item']['basename'] => (object)[
                    'network_wide' => false,
                    'silent' => true,
                ]],
            ];

            dispatch(new ManagerManageItemJob($this->mrid, $payload, 'activate'));
        }

        event(new ManagerItemsInstalled($this->mrid, $result));
    }

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return $this->mrid;
    }
}
