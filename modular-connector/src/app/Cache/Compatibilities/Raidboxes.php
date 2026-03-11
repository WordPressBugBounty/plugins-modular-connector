<?php

namespace Modular\Connector\Cache\Compatibilities;

use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;

class Raidboxes
{
    public static function available()
    {
        return class_exists('RaidboxesNginxCacheFunctions');
    }

    public static function clear()
    {
        try {
            $helper = new \RaidboxesNginxCacheFunctions();
            $helper->purge_cache();
        } catch (\Throwable $e) {
            Log::error($e, [
                'context' => 'Raidboxes Cache Clear',
            ]);
        }
    }
}
