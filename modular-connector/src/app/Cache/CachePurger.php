<?php

namespace Modular\Connector\Cache;

use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;

/**
 * Purges every cache layer it can detect on the site.
 *
 * Each provider is a method that returns false when its plugin/host is not
 * present and true after purging, so detection and purge live side by side.
 */
class CachePurger
{
    /**
     * Provider key => method, run in this order.
     *
     * @var array<string, string>
     */
    private const PROVIDERS = [
        'nitropack' => 'nitroPack',
        'kinsta' => 'kinsta',
        'wprocket' => 'wpRocket',
        'breeze' => 'breeze',
        'flyingpress' => 'flyingPress',
        'litespeed' => 'liteSpeed',
        'w3totalcache' => 'w3TotalCache',
        'wpsupercache' => 'wpSuperCache',
        'wp_optimize' => 'wpOptimize',
        'hummingbird' => 'hummingbird',
        'cache_enabler' => 'cacheEnabler',
        'autoptimize' => 'autoptimize',
        'varnish' => 'varnish',
        'nginx_helper' => 'nginxHelper',
        'raidboxes' => 'raidboxes',
        'wp_fastest_cache' => 'wpFastestCache',
        'xspeed' => 'xSpeed',
        'siteground' => 'siteGround',
    ];

    /**
     * @return void
     */
    public static function purgeAll(): void
    {
        foreach (self::PROVIDERS as $key => $method) {
            try {
                if (static::$method()) {
                    Log::debug('Cache Clear Job', [
                        'cache' => $key,
                        'message' => "Cleared cache {$key}",
                    ]);
                }
            } catch (\Throwable $e) {
                // A third-party purge failing must not stop the remaining providers.
                Log::error($e, [
                    'context' => "Cache Clear {$key}",
                ]);
            }
        }
    }

    protected static function nitroPack(): bool
    {
        // nitropack_purge() only queues the purge for later; the SDK call purges now.
        if (!function_exists('nitropack_sdk_purge')) {
            return false;
        }

        nitropack_sdk_purge(null, null, 'Modular DS cache clear');

        return true;
    }

    protected static function kinsta(): bool
    {
        global $kinsta_cache;

        if (!isset($kinsta_cache) || !class_exists('\Kinsta\CDN_Enabler')) {
            return false;
        }

        if (!empty($kinsta_cache->kinsta_cache_purge)) {
            $kinsta_cache->kinsta_cache_purge->purge_complete_caches();
        }

        return true;
    }

    protected static function wpRocket(): bool
    {
        if (!function_exists('rocket_clean_domain')) {
            return false;
        }

        rocket_clean_domain();

        if (function_exists('get_rocket_option') && get_rocket_option('sitemap_preload') == 1 && function_exists('run_rocket_sitemap_preload')) {
            run_rocket_sitemap_preload();
        }

        if (function_exists('rocket_dismiss_box')) {
            rocket_dismiss_box('rocket_warning_plugin_modification');
        }

        return true;
    }

    protected static function breeze(): bool
    {
        if (!defined('BREEZE_VERSION')) {
            return false;
        }

        // Already covers minified assets, page cache, Varnish, Cloudflare and the object cache.
        do_action('breeze_clear_all_cache');

        return true;
    }

    protected static function flyingPress(): bool
    {
        if (!class_exists('\FlyingPress\Purge')) {
            return false;
        }

        \FlyingPress\Purge::purge_everything();
        \FlyingPress\Preload::preload_cache();

        return true;
    }

    protected static function liteSpeed(): bool
    {
        if (!class_exists('\LiteSpeed\Purge')) {
            return false;
        }

        \LiteSpeed\Purge::purge_all();

        return true;
    }

    protected static function w3TotalCache(): bool
    {
        if (!function_exists('w3tc_flush_all')) {
            return false;
        }

        w3tc_flush_all();

        return true;
    }

    protected static function wpSuperCache(): bool
    {
        if (!function_exists('wp_cache_clear_cache')) {
            return false;
        }

        // wp_cache_clean_cache($prefix, true) only wipes everything for a user with
        // setup_network; this has no capability check.
        wp_cache_clear_cache(is_multisite() ? get_current_blog_id() : 0);

        return true;
    }

    protected static function wpOptimize(): bool
    {
        if (!function_exists('WP_Optimize')) {
            return false;
        }

        WP_Optimize()->get_page_cache()->purge();

        // Not WP_Optimize_Minify_Commands: it needs manage_options and also purges other plugins.
        if (class_exists('\WP_Optimize_Minify_Cache_Functions') && defined('WPO_CACHE_MIN_FILES_DIR')) {
            \WP_Optimize_Minify_Cache_Functions::cache_increment();
            \WP_Optimize_Minify_Cache_Functions::purge();
        }

        return true;
    }

    protected static function hummingbird(): bool
    {
        if (!class_exists('\Hummingbird\Core\Modules\Page_Cache')) {
            return false;
        }

        do_action('wphb_clear_page_cache');

        return true;
    }

    protected static function cacheEnabler(): bool
    {
        if (!class_exists('Cache_Enabler')) {
            return false;
        }

        \Cache_Enabler::clear_complete_cache();

        return true;
    }

    protected static function autoptimize(): bool
    {
        if (!class_exists('autoptimizeCache')) {
            return false;
        }

        // Skip the blocking self-request Autoptimize fires to warm its cache.
        add_filter('autoptimize_filter_speedupper', '__return_false');

        try {
            \autoptimizeCache::clearall();
        } finally {
            remove_filter('autoptimize_filter_speedupper', '__return_false');
        }

        return true;
    }

    protected static function nginxHelper(): bool
    {
        if (!class_exists('Nginx_Helper')) {
            return false;
        }

        do_action('rt_nginx_helper_purge_all');

        return true;
    }

    protected static function varnish(): bool
    {
        if (!class_exists('VarnishPurger')) {
            return false;
        }

        // The same regex purge the plugin's own "Purge all" sends: honours its IPs,
        // port, backend type, extra headers and filters.
        \VarnishPurger::purge_url(\VarnishPurger::the_home_url() . '/?vhp-regex');

        return true;
    }

    protected static function raidboxes(): bool
    {
        if (!class_exists('RaidboxesNginxCacheFunctions')) {
            return false;
        }

        (new \RaidboxesNginxCacheFunctions())->purge_cache();

        return true;
    }

    protected static function wpFastestCache(): bool
    {
        if (!class_exists('WpFastestCache')) {
            return false;
        }

        // `true` also deletes the minified CSS/JS, not just the HTML cache.
        do_action('wpfc_clear_all_cache', true);

        return true;
    }

    protected static function xSpeed(): bool
    {
        if (!class_exists('\XSpeed\Purge_Runner')) {
            return false;
        }

        // Same entry point as xSpeed's dashboard button and `wp xspeed purge`,
        // so every store it manages (page, object, edge) is cleared together.
        \XSpeed\Purge_Runner::run(['all'], 'modular');

        return true;
    }

    protected static function siteGround(): bool
    {
        if (!function_exists('sg_cachepress_purge_everything')) {
            return false;
        }

        // Unlike sg_cachepress_purge_cache() (single URL), this also flushes
        // Memcached, the file cache and the combined/minified assets.
        sg_cachepress_purge_everything();

        return true;
    }
}
