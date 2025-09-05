<?php

namespace Modular\Connector\Listeners;

use Modular\Connector\Events\ManagerItemsInstalled;
use Modular\Connector\Events\ManagerItemsUpgraded;
use Modular\Connector\Jobs\ManagerManageItemJob;
use Modular\Connector\Jobs\ManagerUpgradeDatabaseJob;
use Modular\Connector\Services\Manager\ManagerPlugin;
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
     * @param ManagerItemsUpgraded|ManagerItemsInstalled $event
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

        Log::debug('Try to search plugins with Database');

        if ($event instanceof ManagerItemsUpgraded) {
            $this->handleUpgrade($event);
        } elseif ($event instanceof ManagerItemsInstalled) {
            $this->handleInstall($event);
        }
    }

    /**
     * Dispatches a job to upgrade the database for the given basename.
     *
     * @param $event
     * @param string $basename
     * @return void
     */
    private function dispatchUpgradeDatabase($event, string $basename)
    {
        Log::debug('Sending to upgrade database job', [
            'mrid' => $event->mrid,
            'basename' => $basename,
        ]);

        dispatch(new ManagerUpgradeDatabaseJob($event->mrid, $basename, 'upgrade'));

        HttpUtils::restartQueue($this->currentTime());
    }

    /**
     * @param ManagerItemsInstalled $event
     * @return void
     */
    public function handleInstall(ManagerItemsInstalled $event)
    {
        $type = data_get($event->payload, 'type');
        $basename = data_get($event->payload, 'item.basename');

        if ($type === ManagerPlugin::PLUGIN && in_array($basename, $this->pluginsWithDatabase)) {
            $this->dispatchUpgradeDatabase($event, $basename);
        }
    }

    /**
     * @param ManagerItemsUpgraded $event
     * @return void
     */
    public function handleUpgrade(ManagerItemsUpgraded $event)
    {
        if (array_key_exists('plugins', $event->payload)) {
            Collection::make(data_get($event->payload, 'plugins', []))
                ->filter(fn($plugin) => data_get($plugin, 'success', false))
                ->pluck('item')
                ->filter(fn($plugin) => in_array($plugin, $this->pluginsWithDatabase))
                ->each(function ($basename) use ($event) {
                    $this->dispatchUpgradeDatabase($event, $basename);
                });
        }

        if (data_get($event->payload, 'core.success', false)) {
            $this->dispatchUpgradeDatabase($event, ManagerPlugin::CORE);
        }
    }
}
