<?php

namespace Modular\Connector\Jobs;

use Modular\Connector\Cache\Jobs\CacheClearJob;
use Modular\Connector\Events\ManagerItemsActivated;
use Modular\Connector\Events\ManagerItemsDeactivated;
use Modular\Connector\Events\ManagerItemsDeleted;
use Modular\Connector\Events\ManagerItemsUpgraded;
use Modular\Connector\Facades\Manager;
use Modular\Connector\Facades\Server;
use Modular\Connector\Services\Manager\ManagerPlugin;
use Modular\Connector\Services\Manager\ManagerTheme;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Http\HttpUtils;
use Modular\ConnectorDependencies\Illuminate\Bus\Queueable;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldQueue;
use Modular\ConnectorDependencies\Illuminate\Foundation\Bus\Dispatchable;
use Modular\ConnectorDependencies\Illuminate\Support\Collection;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use Modular\ConnectorDependencies\Illuminate\Support\InteractsWithTime;
use Modular\ConnectorDependencies\Illuminate\Support\Str;
use function Modular\ConnectorDependencies\app;
use function Modular\ConnectorDependencies\data_get;
use function Modular\ConnectorDependencies\dispatch;
use function Modular\ConnectorDependencies\event;

class ManagerManageItemJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
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
    protected string $action;

    /**
     * @var int
     */
    protected int $tries;

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
     * @param int $tries
     */
    public function __construct(string $mrid, $payload, string $action, int $tries = 1)
    {
        $this->mrid = $mrid;
        $this->payload = $payload;
        $this->action = $action;
        $this->tries = $tries;
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
                    $type = ManagerPlugin::PLUGIN;
                    $items = data_get($payload, 'plugins');
                    break;
                case !empty(data_get($payload, 'themes')):
                    $type = ManagerTheme::THEME;
                    $items = data_get($payload, 'themes');
                    break;
                case !empty(data_get($payload, 'core')):
                    $type = ManagerTheme::CORE;
                    $items = data_get($payload, 'core');
                    break;
                case !empty(data_get($payload, 'translations')):
                    $type = ManagerTheme::TRANSLATION;
                    $items = data_get($payload, 'translations');
                    break;
            }
        }

        // Check action by type
        if ($type === ManagerTheme::THEME && in_array($action, ['deactivate'])) {
            return;
        } elseif (in_array($type, [ManagerTheme::CORE, ManagerTheme::TRANSLATION]) && !in_array($action, ['upgrade'])) {
            return;
        } elseif (empty($type)) {
            return;
        }

        $facade = Manager::driver($type);

        try {
            if (in_array($type, [ManagerTheme::CORE, ManagerTheme::TRANSLATION])) {
                $result = $facade->{$action}();
            } else {
                $result = $facade->{$action}($items);
            }
        } finally {
            Server::maintenanceMode(false);
        }

        // FIXME Remove 'type' wrapper
        if ($action === 'upgrade') {
            // If we use HTTP Ajax request, it doesn't work updates, so we must try to use the CRON.
            // FIXME Remove after not using wp-admin/admin-ajax.php
            if (!HttpUtils::isCron() && $this->tries === 1) {
                $tmpResult = $type === ManagerTheme::CORE ? [$result] : $result;

                $allIsFailedByFilePermissions = Collection::make($tmpResult)
                    ->some(fn($item) => !boolval(data_get($item, 'success')) && data_get($item, 'response.error.code') === 'copy_failed_pclzip');

                if ($allIsFailedByFilePermissions) {
                    Log::debug('Retrying the job because of file permissions.', [
                        'mrid' => $this->mrid,
                        'action' => $action,
                        'payload' => $payload,
                    ]);

                    dispatch(new ManagerManageItemJob($this->mrid, $payload, $action, ++$this->tries));

                    // Don't run our AJAX loopback
                    app()->dontDispatchScheduleRun();

                    HttpUtils::restartQueue($this->currentTime());

                    return;
                }
            }

            $type = $type !== ManagerTheme::CORE ? Str::plural($type) : $type;

            $result = [$type => $result];
        }

        $cleanCache = data_get($payload, 'extra.clean_cache', false);

        if ($cleanCache) {
            dispatch(new CacheClearJob());
        }

        // When translations, don't fire any event
        if ($type === ManagerTheme::TRANSLATION . 's') {
            return;
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
