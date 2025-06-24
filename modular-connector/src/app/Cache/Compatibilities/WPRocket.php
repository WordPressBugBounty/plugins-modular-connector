<?php

namespace Modular\Connector\Cache\Compatibilities;

use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;

class WPRocket
{
    public static function available()
    {
        return function_exists('rocket_clean_domain');
    }

    public static function clear()
    {
        try {
            if (function_exists('rocket_clean_domain')) {
                rocket_clean_domain();
            }

            if (function_exists('get_rocket_option')) {
                $sitemapPreload = get_rocket_option('sitemap_preload');

                if ($sitemapPreload == 1 && function_exists('run_rocket_sitemap_preload')) {
                    run_rocket_sitemap_preload();
                }
            }

            if (function_exists('rocket_dismiss_box')) {
                rocket_dismiss_box('rocket_warning_plugin_modification');
            }
        } catch (\Throwable $e) {
            Log::error($e, [
                'context' => 'WPRocket Cache Clear',
            ]);
        }
    }

}

