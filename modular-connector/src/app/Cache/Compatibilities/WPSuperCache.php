<?php

namespace Modular\Connector\Cache\Compatibilities;

use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;

class WPSuperCache
{
    public static function available()
    {
        return defined('WPSC_VERSION_ID');
    }

    public static function clear()
    {
        if (!function_exists('wp_cache_clean_cache')) {
            return;
        }

        try {
            global $file_prefix;

            wp_cache_clean_cache($file_prefix, true);
        } catch (\Throwable $e) {
            Log::error($e, ['context' => 'WPSuperCache Clear']);
        }
    }
}
