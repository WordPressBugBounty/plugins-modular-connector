<?php

namespace Modular\Connector\Listeners;

use Modular\Connector\Events\ManagerItemsInstalled;
use Modular\Connector\Events\ManagerItemsUpgraded;
use Modular\Connector\Jobs\ManagerManageItemJob;
use Modular\Connector\Jobs\ManagerUpgradeDatabaseJob;
use Modular\Connector\Services\Manager\AbstractManager;
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

        // Translation upgrades never fire events, so this listener never receives them.
        // Always dispatch a translation upgrade after any plugin/theme/core upgrade.
        $hasTranslations = Collection::make($event->payload)
            ->contains(fn($item) => data_get($item, 'type') === AbstractManager::TRANSLATION);

        if (!$hasTranslations) {
            dispatch(new ManagerManageItemJob($event->mrid, [
                'type' => AbstractManager::TRANSLATION,
                'items' => [],
                'extra' => [],
            ], 'upgrade'));
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

        if ($type === AbstractManager::PLUGIN && in_array($basename, $this->pluginsWithDatabase)) {
            $this->dispatchUpgradeDatabase($event, $basename);
        }
    }

    /**
     * @param ManagerItemsUpgraded $event
     * @return void
     */
    public function handleUpgrade(ManagerItemsUpgraded $event)
    {
        $items = Collection::make($event->payload);

        // Plugin upgrades: check for database-dependent plugins
        $items->filter(fn($item) => data_get($item, 'type') === AbstractManager::PLUGIN)
            ->filter(fn($item) => data_get($item, 'success', false))
            ->pluck('item')
            ->filter(fn($basename) => in_array($basename, $this->pluginsWithDatabase))
            ->each(fn($basename) => $this->dispatchUpgradeDatabase($event, $basename));

        // Core upgrade: always run database upgrade
        $items->filter(fn($item) => data_get($item, 'type') === AbstractManager::CORE)
            ->filter(fn($item) => data_get($item, 'success', false))
            ->each(fn() => $this->dispatchUpgradeDatabase($event, AbstractManager::CORE));
    }
}
