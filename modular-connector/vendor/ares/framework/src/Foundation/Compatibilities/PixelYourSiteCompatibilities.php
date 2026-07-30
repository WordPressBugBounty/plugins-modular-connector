<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Compatibilities;

use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
/**
 * PixelYourSite PRO update-detection compatibility.
 *
 * PixelYourSite PRO uses an EDD SL updater (fork v1.6.14) for the core plugin
 * and its add-ons (Pinterest, Bing, Super Pack, ...), but the updaters are
 * only instantiated on `admin_init` priority 0 (PYS::updatePlugin()). That
 * hook never fires outside wp-admin, so in our request contexts no updater
 * registers and update detection sees nothing.
 *
 * The fix executes ONLY their registered admin_init callback (matched by
 * class/method via $wp_filter, same approach as the YITH upgrader fix)
 * instead of firing the whole admin_init hook, which would run every
 * plugin's admin logic.
 *
 * Once instantiated, their updaters hook `site_transient_update_plugins`
 * (the READ filter, not pre_set): every get_site_transient('update_plugins')
 * call re-injects their entries, querying their EDD endpoint
 * (edd_action=get_version, cached in `pys_api_request_*` transients). Our
 * detection captures them through get_plugin_updates() / the transient read
 * in AbstractManager. Without a valid license EDD returns an empty `package`
 * and the update is correctly excluded.
 */
class PixelYourSiteCompatibilities
{
    /**
     * Indicates if the compatibility has already been applied.
     */
    private static bool $applied = \false;
    /**
     * Instantiate PixelYourSite's updaters in non-admin contexts.
     *
     * Must run AFTER plugins are loaded (PYS registers its admin_init hook
     * in its constructor) — both call sites run on after_setup_theme.
     *
     * @return void
     */
    public static function register(): void
    {
        if (static::$applied || !defined('PYS_VERSION')) {
            return;
        }
        static::$applied = \true;
        // \PixelYourSite\PYS::class resolves at compile time (no autoload);
        // the PixelYourSite namespace is excluded in scoper.inc.php.
        $called = Compatibilities::callFilterByClassName('admin_init', \PixelYourSite\PYS::class, 'updatePlugin', 0);
        if ($called) {
            Log::debug('PixelYourSiteCompatibilities: PYS updaters instantiated');
        }
    }
}
