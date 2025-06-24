<?php

namespace Modular\Connector\Cache\Compatibilities;

use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;

class FlyingPress
{
    public static function available()
    {
        return class_exists('FlyingPress\Purge') || class_exists('\\FlyingPress\\Purge');
    }

    public static function clear()
    {
        try {
            \FlyingPress\Purge::purge_everything();
            \FlyingPress\Preload::preload_cache();
        } catch (\Exception $e) {
            Log::error($e, [
                'context' => 'FlyingPress Cache Clear',
            ]);
        }
    }
}
