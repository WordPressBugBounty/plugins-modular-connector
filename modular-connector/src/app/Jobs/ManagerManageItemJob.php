<?php

namespace Modular\Connector\Jobs;

use Modular\Connector\Events\ManagerItemsActivated;
use Modular\Connector\Events\ManagerItemsDeactivated;
use Modular\Connector\Events\ManagerItemsDeleted;
use Modular\Connector\Events\ManagerItemsUpgraded;
use Modular\Connector\Facades\Core;
use Modular\Connector\Facades\Database;
use Modular\Connector\Facades\Plugin;
use Modular\Connector\Facades\Theme;
use Modular\Connector\Facades\Translation;

class ManagerManageItemJob extends AbstractJob
{
    /**
     * @var string
     */
    protected string $mrid;

    /**
     * @var mixed
     */
    protected $payload;

    protected string $action;

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

    public function handle()
    {
        $payload = $this->payload;
        $action = $this->action;

        if (isset($payload->plugins)) {
            $result = Plugin::$action($payload->plugins);

            // FIXME Remove 'type' wrapper
            if ($action === 'upgrade') {
                $result = ['plugins' => $result];
            }
        } elseif (isset($payload->themes) && $action !== 'deactivate') {
            $result = Theme::$action($payload->themes);

            // FIXME Remove 'type' wrapper
            if ($action === 'upgrade') {
                $result = ['themes' => $result];

            }
        } elseif (isset($payload->core) && $action === 'upgrade') {
            $result = Core::upgrade();

            // FIXME Remove 'type' wrapper
            if ($action === 'upgrade') {
                $result = ['core' => $result];
            }
        } elseif (isset($payload->translations) && $action === 'upgrade') {
            $result = Translation::upgrade();

            // FIXME Remove 'type' wrapper
            if ($action === 'upgrade') {
                $result = ['translations' => $result];
            }
        } elseif (isset($payload->database) && $action === 'upgrade') {
            $result = Database::upgrade();

            // FIXME Remove 'type' wrapper
            if ($action === 'upgrade') {
                $result = ['database' => $result];
            }
        } else {
            return;
        }

        switch ($action) {
            case 'activate':
                ManagerItemsActivated::dispatch($this->mrid, $result);
                break;
            case 'deactivate':
                ManagerItemsDeactivated::dispatch($this->mrid, $result);
                break;
            case 'delete':
                ManagerItemsDeleted::dispatch($this->mrid, $result);
                break;
            case 'upgrade':
                ManagerItemsUpgraded::dispatch($this->mrid, $result);
                break;
        }

        return $result;
    }
}
