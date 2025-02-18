<?php

namespace Modular\Connector\Listeners;

use Modular\Connector\Jobs\ManagerManageItemJob;
use function Modular\ConnectorDependencies\dispatch;

class UpgradeTranslationsEventListener
{
    /**
     * @param $event
     * @return void
     */
    public static function handle($event)
    {
        if (!array_key_exists('translations', $event->payload)) {
            $payload = [
                'type' => 'translation',
                'items' => [],
            ];

            dispatch(new ManagerManageItemJob($event->mrid, $payload, 'upgrade'));
        }
    }
}
