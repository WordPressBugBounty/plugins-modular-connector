<?php

namespace Modular\Connector\Jobs;

use Modular\Connector\Events\ManagerItemsActivated;
use Modular\Connector\Events\ManagerItemsDeactivated;
use Modular\Connector\Events\ManagerItemsDeleted;
use Modular\Connector\Events\ManagerItemsUpgraded;
use Modular\Connector\Facades\Manager;
use Modular\Connector\Facades\Server;
use Modular\ConnectorDependencies\Illuminate\Bus\Queueable;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldQueue;
use Modular\ConnectorDependencies\Illuminate\Foundation\Bus\Dispatchable;
use Modular\ConnectorDependencies\Illuminate\Support\Str;
use function Modular\ConnectorDependencies\data_get;
use function Modular\ConnectorDependencies\event;

class ManagerManageItemJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
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
     * @var string
     */
    protected string $action;

    /**
     * The number of seconds after which the job's unique lock will be released.
     *
     * @var int
     */
    public $uniqueFor = 2 * 3600; // 2 hour

    /**
     * @param string $mrid
     * @param $payload
     * @param string $action
     */
    public function __construct(string $mrid, $payload, string $action)
    {
        $this->mrid = $mrid;
        $this->payload = $payload;
        $this->action = $action;
    }

    public function handle(): void
    {
        $payload = $this->payload;
        $action = $this->action;

        $type = data_get($payload, 'type');
        $items = data_get($payload, 'items');

        if (empty($type)) {
            switch (true) {
                case !empty(data_get($payload, 'plugins')):
                    $type = 'plugin';
                    $items = data_get($payload, 'plugins');
                    break;
                case !empty(data_get($payload, 'themes')):
                    $type = 'theme';
                    $items = data_get($payload, 'themes');
                    break;
                case !empty(data_get($payload, 'core')):
                    $type = 'core';
                    $items = data_get($payload, 'core');
                    break;
                case !empty(data_get($payload, 'translations')):
                    $type = 'translation';
                    $items = data_get($payload, 'translations');
                    break;
                case !empty(data_get($payload, 'database')):
                    $type = 'database';
                    $items = data_get($payload, 'database');
                    break;
            }
        }

        // Check action by type
        if ($type === 'theme' && in_array($action, ['deactivate'])) {
            return;
        } elseif (in_array($type, ['core', 'translation', 'database']) && !in_array($action, ['upgrade'])) {
            return;
        } elseif (empty($type)) {
            return;
        }

        $facade = Manager::driver($type);

        try {
            if (in_array($type, ['core', 'translation'])) {
                $result = $facade->{$action}();
            } else {
                $result = $facade->{$action}($items);
            }
        } finally {
            Server::maintenanceMode(false);
        }

        // FIXME Remove 'type' wrapper
        if ($action === 'upgrade') {
            $type = $type !== 'core' ? Str::plural($type) : $type;

            $result = [$type => $result];
        }

        switch ($action) {
            case 'activate':
                event(new ManagerItemsActivated($this->mrid, $result));
                break;
            case 'deactivate':
                event(new ManagerItemsDeactivated($this->mrid, $result));
                break;
            case 'delete':
                event(new ManagerItemsDeleted($this->mrid, $result));
                break;
            case 'upgrade':
                event(new ManagerItemsUpgraded($this->mrid, $result));
                break;
        }
    }

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return $this->mrid;
    }
}
