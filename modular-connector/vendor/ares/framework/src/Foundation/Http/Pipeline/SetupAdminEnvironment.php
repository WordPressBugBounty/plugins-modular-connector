<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Http\Pipeline;

use Modular\ConnectorDependencies\Ares\Framework\Foundation\Http\HttpUtils;
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
        // Prevent wp_cron from running during our requests
        remove_action('init', 'wp_cron');
    }
}
