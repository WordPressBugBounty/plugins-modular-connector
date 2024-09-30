<?php

namespace Modular\Connector\Listeners;

use Modular\Connector\Jobs\ManagerManageItemJob;

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
                'translations' => '',
            ];

            ManagerManageItemJob::dispatch($event->mrid, $payload, 'upgrade');
        }
    }
}
