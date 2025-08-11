<?php

namespace Modular\Connector\Listeners;

use Modular\Connector\Jobs\ManagerManageItemJob;
use Modular\Connector\Jobs\ManagerUpgradeDatabaseJob;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Http\HttpUtils;
use Modular\ConnectorDependencies\Illuminate\Support\Collection;
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
        Log::debug('PostUpgradeEventListener: Handling post upgrade event', [
            'mrid' => $event->mrid,
            'payload' => $event->payload,
        ]);

        if (!array_key_exists('translations', $event->payload)) {
            $payload = [
                'type' => 'translation',
                'items' => [],
            ];

            dispatch(new ManagerManageItemJob($event->mrid, $payload, 'upgrade'));
        }

        if (array_key_exists('plugins', $event->payload)) {
            Log::debug('Try to search plugins with Database');

            Collection::make(data_get($event->payload, 'plugins', []))
                ->filter(fn($plugin) => data_get($plugin, 'success', false))
                ->pluck('item')
                ->filter(fn($plugin) => in_array($plugin, $this->pluginsWithDatabase))
                ->each(function ($basename) use ($event) {
                    Log::debug('Sending to upgrade database job', [
                        'mrid' => $event->mrid,
                        'basename' => $basename,
                    ]);

                    dispatch(new ManagerUpgradeDatabaseJob($event->mrid, $basename, 'upgrade'));

                    HttpUtils::restartQueue($this->currentTime());
                });
        }

        if (data_get($event->payload, 'core.success', false)) {
            dispatch(new ManagerUpgradeDatabaseJob($event->mrid, 'core', 'upgrade'));

            HttpUtils::restartQueue($this->currentTime());
        }
    }
}
