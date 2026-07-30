<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Http\Pipeline;

use Modular\ConnectorDependencies\Ares\Framework\Foundation\Compatibilities\PixelYourSiteCompatibilities;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Compatibilities\SeedProdCompatibilities;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Compatibilities\TrxUpdaterCompatibilities;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Http\HttpUtils;
use Closure;
use Modular\ConnectorDependencies\Illuminate\Http\Request;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
/**
 * Force compatibility fixes for premium plugin/theme upgraders.
 *
 * This pipe handles six compatibility fixes:
 * 1. YITH Upgrader - Forces registration of YITH upgrade system that initializes late
 * 2. Divi Upgrader - Registers Divi's update component (normally registered on init)
 * 3. YITH Download - Resolves the plugin being upgraded in non-AJAX contexts
 * 4. ThemeREX Bridge - Bridges trx_updater's custom update system into the WP transients
 * 5. PixelYourSite - Instantiates its admin_init-gated EDD updaters (core + add-ons)
 * 6. SeedProd - Instantiates its admin_init-gated updater
 *
 * Note: User login is handled earlier by SetupAdminEnvironment so themes
 * with authorization gates register their hooks during WordPress init.
 *
 * Runs AFTER BeforeLogin compatibility fixes are applied.
 */
class ForceCompatibilities
{
    /**
     * Handle the compatibility fixes.
     *
     * @param Request $request
     * @param Closure $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // 1. Register YITH upgrade system that initializes late on wp_loaded priority 99
        try {
            $this->registerYithUpgrader();
        } catch (\Throwable $e) {
            Log::warning('ForceCompatibilities: YITH upgrader failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        }
        // 2. Register Divi upgrade component (normally registered on init)
        try {
            $this->registerDiviUpgrader();
        } catch (\Throwable $e) {
            Log::warning('ForceCompatibilities: Divi upgrader failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        }
        // 3. Fix YITH download: their upgrader_pre_download reads $_POST['plugin']
        // which doesn't exist in our context. Fire pre_auto_update so YITH sets
        // plugin_upgrading before its own upgrader_pre_download handler runs.
        try {
            $this->registerYithDownloadCompat();
        } catch (\Throwable $e) {
            Log::warning('ForceCompatibilities: YITH download compat failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        }
        // 4. Bridge ThemeREX Updater's custom update system into the WP transients.
        // Must run before init: TGMPA checks the tgmpa_load filter there.
        try {
            TrxUpdaterCompatibilities::register();
        } catch (\Throwable $e) {
            Log::warning('ForceCompatibilities: ThemeREX bridge failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        }
        // 5. Instantiate PixelYourSite's updaters: they only register on
        // admin_init, which never fires in our request contexts.
        try {
            PixelYourSiteCompatibilities::register();
        } catch (\Throwable $e) {
            Log::warning('ForceCompatibilities: PixelYourSite updaters failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        }
        // 6. Instantiate SeedProd's updater: same admin_init gate as PYS.
        try {
            SeedProdCompatibilities::register();
        } catch (\Throwable $e) {
            Log::warning('ForceCompatibilities: SeedProd updater failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        }
        return $next($request);
    }
    /**
     * Register YITH upgrade system if it hasn't been registered yet.
     *
     * YITH plugins register their license and update systems on wp_loaded with priority 99,
     * but we execute at priority 10, so we need to call their callbacks manually.
     *
     * This method is YITH-specific. If other premium plugins need similar treatment,
     * add separate methods for each (e.g., registerAnotherPluginUpgrader()).
     *
     * @return void
     */
    private function registerYithUpgrader(): void
    {
        global $wp_filter;
        // Check if wp_loaded hook has actions registered at priority 99
        if (!isset($wp_filter['wp_loaded']) || !isset($wp_filter['wp_loaded']->callbacks[99])) {
            return;
        }
        // Get all callbacks registered at priority 99 on wp_loaded
        $callbacks = $wp_filter['wp_loaded']->callbacks[99];
        foreach ($callbacks as $callback) {
            // Check if this is a YITH callback
            if (!$this->isYithCallback($callback)) {
                continue;
            }
            // Execute the YITH callback manually
            if (is_callable($callback['function'])) {
                call_user_func($callback['function']);
            }
        }
    }
    /**
     * Register Divi upgrader if it hasn't been registered yet.
     *
     * Divi's upgrade system is registered on init, but we execute before that,
     * so we need to call their registration function manually.
     *
     * @return void
     */
    private function registerDiviUpgrader(): void
    {
        if (HttpUtils::isCron() || !function_exists('et_register_updates_component')) {
            return;
        }
        add_action('init', 'et_register_updates_component');
    }
    /**
     * Fix YITH plugin downloads in non-AJAX contexts.
     *
     * YITH's upgrader_pre_download (priority 10) identifies the plugin being
     * upgraded via get_plugin_upgrading(). For WP_Ajax_Upgrader_Skin it reads
     * $_POST['plugin'], which doesn't exist in our programmatic context.
     *
     * However, YITH also checks $this->plugin_upgrading first, which is set by
     * the pre_auto_update action via catch_plugin_upgrading(). We hook
     * upgrader_pre_download at priority 9 to resolve the plugin basename from
     * skin->plugin_info['Name'] (the only reliable data available) and fire
     * pre_auto_update so YITH sets plugin_upgrading before its handler runs.
     *
     * Uses the same Name-matching approach YITH uses for WP-CLI support.
     *
     * @return void
     */
    private function registerYithDownloadCompat(): void
    {
        add_filter('upgrader_pre_download', function ($reply, $package, $upgrader) {
            if (!class_exists('YITH\PluginUpgrade\Licences', \false)) {
                return $reply;
            }
            if (!$upgrader instanceof \Plugin_Upgrader) {
                return $reply;
            }
            $pluginName = $upgrader->skin->plugin_info['Name'] ?? '';
            if (empty($pluginName)) {
                return $reply;
            }
            // Resolve basename by matching Name against YITH registered products,
            // same approach YITH uses for WP-CLI in get_plugin_upgrading().
            $plugin = $this->resolveYithPluginBasename($pluginName);
            if (empty($plugin)) {
                return $reply;
            }
            do_action('pre_auto_update', 'plugin', (object) ['plugin' => $plugin], \ABSPATH);
            return $reply;
        }, 9, 3);
    }
    /**
     * Resolve a YITH plugin basename from its display name.
     *
     * @param string $pluginName The plugin display name from plugin_info['Name']
     * @return string The plugin basename (e.g. 'yith-.../init.php') or empty if not found
     */
    private function resolveYithPluginBasename(string $pluginName): string
    {
        $products = \YITH\PluginUpgrade\Licences::instance()->get_products();
        foreach ($products as $init => $info) {
            if (html_entity_decode($pluginName) === html_entity_decode($info['Name'])) {
                return $init;
            }
        }
        return '';
    }
    /**
     * Check if a callback belongs to a YITH plugin.
     *
     * @param array $callback The callback data from wp_filter
     * @return bool
     */
    private function isYithCallback(array $callback): bool
    {
        $function = $callback['function'];
        // Array callback: [object, method]
        if (is_array($function) && isset($function[0])) {
            $object = $function[0];
            // Check if object's class name contains YITH
            if (is_object($object)) {
                $className = get_class($object);
                return stripos($className, 'YITH') !== \false || stripos($className, 'YIT') !== \false;
            }
        }
        // String callback: 'function_name' or 'Class::method'
        if (is_string($function)) {
            return stripos($function, 'yith') !== \false || stripos($function, 'yit') !== \false;
        }
        return \false;
    }
}
