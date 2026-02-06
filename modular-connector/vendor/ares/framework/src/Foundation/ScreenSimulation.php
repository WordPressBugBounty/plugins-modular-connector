<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation;

use Modular\ConnectorDependencies\Ares\Framework\Foundation\Http\HttpUtils;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
/**
 * WordPress upgrader utilities.
 *
 * Provides helper method for including necessary WordPress upgrader files
 * and screen classes.
 *
 * NOTE: Environment setup (constants, $_SERVER, $GLOBALS) is handled by
 * the SetupAdminEnvironment pipe for HTTP requests.
 *
 * @see \Ares\Framework\Foundation\Http\Pipeline\SetupAdminEnvironment
 */
class ScreenSimulation
{
    /**
     * Makes the necessary WordPress upgrader includes
     * to handle plugin and themes functionality.
     */
    public static function includeUpgrader(): void
    {
        if (!function_exists('wp_update_plugins') || !function_exists('wp_update_themes')) {
            ob_start();
            require_once \ABSPATH . 'wp-admin/includes/update.php';
            ob_end_flush();
            ob_end_clean();
        }
        if (!class_exists('WP_Upgrader')) {
            require_once \ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        }
        if (!function_exists('wp_install')) {
            require_once \ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        if (!function_exists('plugins_api')) {
            require_once \ABSPATH . 'wp-admin/includes/plugin-install.php';
        }
        // Include WordPress screen classes
        if (!class_exists('WP_Screen')) {
            require_once \ABSPATH . 'wp-admin/includes/class-wp-screen.php';
        }
        if (!function_exists('set_current_screen')) {
            require_once \ABSPATH . 'wp-admin/includes/screen.php';
        }
        if (!function_exists('add_meta_box')) {
            require_once \ABSPATH . 'wp-admin/includes/template.php';
        }
        if (empty($GLOBALS['wp_filesystem'])) {
            WP_Filesystem();
        }
        if (empty($GLOBALS['wp_theme_directories'])) {
            register_theme_directory(get_theme_root());
        }
    }
}
