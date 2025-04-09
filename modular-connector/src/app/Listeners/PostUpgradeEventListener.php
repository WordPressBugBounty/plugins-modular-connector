<?php

namespace Modular\Connector\Listeners;

use Modular\Connector\Jobs\ManagerManageItemJob;
use Modular\Connector\Jobs\ManagerUpgradeDatabaseJob;
use Modular\ConnectorDependencies\Illuminate\Support\Collection;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Cache;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use Modular\ConnectorDependencies\Illuminate\Support\InteractsWithTime;
use function Modular\ConnectorDependencies\data_get;
use function Modular\ConnectorDependencies\dispatch;

class PostUpgradeEventListener
{
    use InteractsWithTime;

    /**
     * @var array|string[]
     */
    private array $pluginsWithDatabase = [
        'woocommerce/woocommerce.php',
        'elementor/elementor.php',
        'elementor-pro/elementor-pro.php',
    ];

    /**
     * @param $event
     * @return void
     */
    public function handle($event)
    {
        if (!array_key_exists('translations', $event->payload)) {
            $payload = [
                'type' => 'translation',
                'items' => [],
            ];

            dispatch(new ManagerManageItemJob($event->mrid, $payload, 'upgrade'));
        }

        if (array_key_exists('plugins', $event->payload)) {
            Collection::make(data_get($event->payload, 'plugins', []))
                ->filter(fn($plugin) => data_get($plugin, 'success', false))
                ->pluck('item')
                ->filter(fn($plugin) => in_array($plugin, $this->pluginsWithDatabase))
                ->each(function ($basename) use ($event) {
                    dispatch(new ManagerUpgradeDatabaseJob($event->mrid, $basename, 'upgrade'));

                    $this->restartQueue();
                });
        }

        if (data_get($event->payload, 'core.success', false)) {
            dispatch(new ManagerUpgradeDatabaseJob($event->mrid, 'core', 'upgrade'));
            $this->restartQueue();
        }
    }

    /**
     * @return void
     */
    protected function restartQueue()
    {
        Cache::forever('illuminate:queue:restart', $this->currentTime());

        Log::info('Broadcasting queue restart signal.');
    }
}
