<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Compatibilities;

use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
/**
 * SeedProd Pro update-detection compatibility.
 *
 * SeedProd Pro registers its updater (SeedProd_Updater) via
 * add_action('admin_init', 'seedprod_pro_updater', 0) — a hook that never
 * fires in our request contexts, so no updater registers and update
 * detection sees nothing.
 *
 * The registration function is loaded unconditionally by the plugin, so the
 * fix simply calls it directly. Once instantiated, the updater hooks
 * `pre_set_site_transient_update_plugins` (the save filter), which our
 * detection captures both via the AbstractManager simulation and via
 * wp_update_plugins(). Without a valid API key the remote returns an error
 * and nothing is injected.
 *
 * NOTE: SeedProd_Updater's constructor also requires
 * current_user_can('update_plugins') — satisfied by the early admin login in
 * direct/loopback requests. In native wp-cron there is no logged-in user, so
 * the updater no-ops there (harmless). This is BY DESIGN: logging in a user
 * during cron is not an option — it changes is_user_logged_in() for every
 * plugin running cron tasks (WooCommerce fatals/redirects were reported when
 * we did it in the past) and widens the security surface.
 */
class SeedProdCompatibilities
{
    /**
     * Indicates if the compatibility has already been applied.
     */
    private static bool $applied = \false;
    /**
     * Instantiate SeedProd's updater in non-admin contexts.
     *
     * Must run after the user login (SetupAdminEnvironment) so the
     * constructor's capability check passes — both call sites run on
     * after_setup_theme.
     *
     * @return void
     */
    public static function register(): void
    {
        if (static::$applied || !defined('SEEDPROD_PRO_VERSION')) {
            return;
        }
        static::$applied = \true;
        if (!function_exists('seedprod_pro_updater')) {
            return;
        }
        seedprod_pro_updater();
        Log::debug('SeedProdCompatibilities: SeedProd updater instantiated');
    }
}
