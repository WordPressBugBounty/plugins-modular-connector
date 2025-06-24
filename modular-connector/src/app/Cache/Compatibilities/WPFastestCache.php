<?php

namespace Modular\Connector\Cache\Compatibilities;

use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;

class WPFastestCache
{
    public static function available()
    {
        return class_exists('WpFastestCache');
    }

    public static function clear()
    {
        if (!class_exists('WpFastestCache')) {
            return;
        }

        try {
            $wpfc = new WpFastestCache();
            $wpfc->deleteCache();
        } catch (\Throwable $e) {
            Log::error($e, ['context' => 'WPSuperCache Clear']);
        }
    }
}
