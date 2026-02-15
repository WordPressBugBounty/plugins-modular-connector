<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Http\Pipeline;

use Modular\ConnectorDependencies\Ares\Framework\Foundation\Http\HttpUtils;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\ServerSetup;
use Closure;
use Modular\ConnectorDependencies\Illuminate\Http\Request;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
/**
 * Setup WordPress admin environment for direct requests.
 *
 * This pipe:
 * - Defines WP_ADMIN, WP_NETWORK_ADMIN, WP_BLOG_ADMIN constants
 * - Simulates wp-admin environment ($_SERVER, $_COOKIE, $GLOBALS)
 * - Disables WordPress auto-actions (redirects, auto-updates, wp_cron)
 * - Logs in the admin user early so themes with authorization gates
 */
class SetupAdminEnvironment
{
    /**
     * Handle the admin environment setup.
     *
     * @param Request $request
     * @param Closure $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Setup admin constants (WP_ADMIN, WP_NETWORK_ADMIN, etc.)
        $this->setupAdminConstants();
        // Simulate wp-admin environment
        $this->simulateAdminEnvironment();
        // Disable WordPress automatic actions during our operations
        $this->disableWordPressAutoActions();
        // Login admin user early so themes loading during WordPress init
        // can pass authorization gates and register their hooks
        $this->loginAdminUser();
        return $next($request);
    }
    /**
     * Setup WordPress admin constants.
     *
     * This defines the necessary constants to make WordPress and plugins
     * believe they are running in an admin context.
     */
    private function setupAdminConstants(): void
    {
        // Some hosting blocks write permissions for wp-content/mu-plugins,
        // so in that case we won't be able to define WP_ADMIN constant
        if (empty($GLOBALS['modular_is_mu_plugin'])) {
            return;
        }
        if (!defined('WP_ADMIN')) {
            define('WP_ADMIN', \true);
        }
        if (HttpUtils::isMultisite()) {
            if (!defined('WP_NETWORK_ADMIN')) {
                define('WP_NETWORK_ADMIN', \true);
                Log::debug('SetupAdminEnvironment: Defined WP_NETWORK_ADMIN constant (multisite)');
            }
        } else if (!defined('WP_NETWORK_ADMIN')) {
            define('WP_NETWORK_ADMIN', \false);
        }
        if (!defined('WP_USER_ADMIN')) {
            define('WP_USER_ADMIN', \false);
        }
        if (!defined('WP_BLOG_ADMIN')) {
            define('WP_BLOG_ADMIN', \true);
        }
    }
    /**
     * Simulate WordPress admin environment.
     *
     * Sets $_SERVER, $_COOKIE, and $GLOBALS to make WordPress
     * and plugins believe we're in wp-admin.
     */
    private function simulateAdminEnvironment(): void
    {
        $path = 'update-core.php';
        // Simulate being in wp-admin
        $_SERVER['PHP_SELF'] = '/wp-admin/' . $path;
        // Prevent infinite redirects from WordPress HTTPS plugin
        $_COOKIE['redirect_count'] = '10';
        if (defined('FORCE_SSL_ADMIN') && \FORCE_SSL_ADMIN) {
            $_SERVER['HTTPS'] = 'on';
            $_SERVER['SERVER_PORT'] = '443';
        }
        if (!isset($GLOBALS['pagenow'])) {
            $GLOBALS['pagenow'] = $path;
        }
        if (!isset($GLOBALS['hook_suffix'])) {
            $GLOBALS['hook_suffix'] = '';
        }
    }
    /**
     * Disable WordPress automatic actions during direct requests.
     *
     * Prevents WordPress from:
     * - Redirecting (we use Laravel Response for redirects)
     * - Running auto-updates during our operations
     * - Running wp_cron during our requests
     */
    private function disableWordPressAutoActions(): void
    {
        // Prevent WordPress redirects (we use Laravel Response)
        add_filter('wp_redirect', '__return_false');
        // Disable auto updates during our operations
        add_filter('auto_update_core', '__return_false', \PHP_INT_MAX);
        add_filter('auto_update_translation', '__return_false', \PHP_INT_MAX);
        add_filter('auto_update_theme', '__return_false', \PHP_INT_MAX);
        add_filter('auto_update_plugin', '__return_false', \PHP_INT_MAX);
        add_filter('automatic_updater_disabled', '__return_true');
        // Prevent wp_cron hook
        remove_action('init', 'wp_cron');
    }
    /**
     * Login admin user early so plugins and themes see an authenticated user.
     *
     * When running as mu-plugin (step 3), defers to muplugins_loaded (step 4)
     * so login happens BEFORE regular plugins (step 5) and themes (step 9).
     *
     * When NOT a mu-plugin (regular plugin, step 5+), logs in immediately
     * since muplugins_loaded has already fired.
     *
     * wp_cookie_constants() is safe to call at this point: it only defines
     * constants via !defined() checks using get_site_option() (DB is ready
     * since step 1). When WordPress calls it again at step 7, it's a no-op.
     */
    private function loginAdminUser(): void
    {
        if (empty($GLOBALS['modular_is_mu_plugin'])) {
            self::doLogin();
            return;
        }
        add_action('muplugins_loaded', [self::class, 'doLogin']);
    }
    /**
     * @internal Called directly or via muplugins_loaded hook.
     */
    public static function doLogin(): void
    {
        try {
            ServerSetup::loginAs();
        } catch (\Throwable $e) {
            Log::warning('SetupAdminEnvironment: Early login failed', ['error' => $e->getMessage()]);
        }
    }
}
