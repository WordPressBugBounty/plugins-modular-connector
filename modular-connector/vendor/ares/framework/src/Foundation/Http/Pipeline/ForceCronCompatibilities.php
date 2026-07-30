<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Http\Pipeline;

use Modular\ConnectorDependencies\Ares\Framework\Foundation\Compatibilities\PixelYourSiteCompatibilities;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Compatibilities\SeedProdCompatibilities;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Compatibilities\TrxUpdaterCompatibilities;
use Closure;
use Modular\ConnectorDependencies\Illuminate\Http\Request;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
/**
 * Force compatibility fixes for cron-executed jobs.
 *
 * Cron requests (wp-cron.php) don't run the direct-request pipelines, so
 * compatibilities that must also apply when jobs execute via native wp-cron
 * (e.g. MODULAR_CONNECTOR_LOOPBACK = false) are registered here.
 *
 * This pipe handles three compatibility fixes:
 * 1. ThemeREX Bridge - Bridges trx_updater's custom update system into the WP transients
 * 2. PixelYourSite - Instantiates its admin_init-gated EDD updaters (core + add-ons)
 * 3. SeedProd - Instantiates its admin_init-gated updater (no-ops in cron: its
 *    constructor checks the update_plugins capability and there is no user here)
 *
 * IMPORTANT: never log in a user in the cron context to satisfy capability
 * checks — it changes is_user_logged_in() for every plugin running cron tasks
 * (WooCommerce fatals/redirects were reported when we did it in the past) and
 * widens the security surface. Capability-gated updaters simply don't report
 * in cron; the loopback context covers them.
 *
 * NOT included on purpose (direct-request-only fixes from ForceCompatibilities):
 * - YITH upgrader: only needed because direct requests exit at wp_loaded
 *   priority 10, before YITH registers at 99 — in cron the request completes
 *   normally and YITH registers itself; forcing it here would run twice.
 * - Divi upgrader: registers itself on init in cron (its fix skips cron).
 * - YITH download fix: piggybacks on the natural registration above.
 *
 * Runs on after_setup_theme (before init), same as the Handler Pipeline.
 */
class ForceCronCompatibilities
{
    /**
     * Handle the cron compatibility fixes.
     *
     * @param Request $request
     * @param Closure $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // 1. Bridge ThemeREX Updater's custom update system into the WP transients.
        // Must run before init: TGMPA checks the tgmpa_load filter there.
        try {
            TrxUpdaterCompatibilities::register();
        } catch (\Throwable $e) {
            Log::warning('ForceCronCompatibilities: ThemeREX bridge failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        }
        // 2. Instantiate PixelYourSite's updaters: they only register on
        // admin_init, which never fires in cron requests either.
        try {
            PixelYourSiteCompatibilities::register();
        } catch (\Throwable $e) {
            Log::warning('ForceCronCompatibilities: PixelYourSite updaters failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        }
        // 3. Instantiate SeedProd's updater: same admin_init gate as PYS.
        try {
            SeedProdCompatibilities::register();
        } catch (\Throwable $e) {
            Log::warning('ForceCronCompatibilities: SeedProd updater failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        }
        return $next($request);
    }
}
