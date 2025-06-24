<?php

namespace Modular\Connector\Cache\Compatibilities;

use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;

class Kinsta
{
    public static function available()
    {
        global $kinsta_cache;

        return isset($kinsta_cache) && class_exists('\\Kinsta\\CDN_Enabler');
    }

    public static function clear()
    {
        global $kinsta_cache;

        try {
            if (!empty($kinsta_cache->kinsta_cache_purge)) {
                $kinsta_cache->kinsta_cache_purge->purge_complete_caches();
            }
        } catch (\Exception $e) {
            Log::error($e, [
                'context' => 'Kinsta Cache Clear',
            ]);
        }
    }
}

