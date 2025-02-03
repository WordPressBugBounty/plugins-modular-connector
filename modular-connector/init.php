<?php
/**
 * Plugin Name: Modular Connector
 * Plugin URI: https://modulards.com/herramienta-gestion-webs/
 * Description: Connect and manage all your WordPress websites in an easier and more efficient way. Backups, bulk updates, Uptime Monitor, statistics, security, performance, client reports and much more.
 * Version: 1.10.4
 * License: GPL v3.0
 * License URI: https://www.gnu.org/licenses/gpl.html
 * Requires PHP: 7.4
 * Requires at least: 6.0
 * Author: Modular DS
 * Author URI: https://modulards.com/
 * Text Domain: modular-connector
 * Domain Path: /languages/
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/src/bootstrap/autoload.php';

define('MODULAR_CONNECTOR_BASENAME', plugin_basename(__FILE__));
define('MODULAR_CONNECTOR_VERSION', '1.10.4');
define('MODULAR_ARES_CACHE_PREFIX', 'modular_connector_cache_');
define('MODULAR_ARES_SCHEDULE_HOOK', 'modular_connector_run_schedule');
define('MODULAR_CONNECTOR_STORAGE_PATH', untrailingslashit(WP_CONTENT_DIR) . DIRECTORY_SEPARATOR . 'modular_storage');
define('MODULAR_CONNECTOR_BACKUPS_PATH', untrailingslashit(WP_CONTENT_DIR) . DIRECTORY_SEPARATOR . 'modular_backups');

if (!defined('MODULAR_CONNECTOR_LOG_LEVEL')) {
    define('MODULAR_CONNECTOR_LOG_LEVEL', 'error');
}

add_action('plugins_loaded', function () {
    load_plugin_textdomain('modular-connector', false, dirname(MODULAR_CONNECTOR_BASENAME) . '/languages');
});

if (function_exists('register_deactivation_hook')) {
    register_deactivation_hook(__FILE__, [\Modular\Connector\Facades\Manager::class, 'deactivate']);
}

if (function_exists('register_uninstall_hook')) {
    register_uninstall_hook(__FILE__, [Modular\Connector\Helper\OauthClient::class, 'uninstall']);
}

add_action('admin_enqueue_scripts', function () {
    wp_enqueue_style('modular-connector-styles', plugin_dir_url(MODULAR_CONNECTOR_BASENAME) . '/src/resources/css/index.css');
});

add_action('admin_menu', function () {
    $settings = new \Modular\Connector\WordPress\Settings();

    add_management_page(
        $settings->title(),
        $settings->title(),
        'manage_options',
        $settings->slug,
        [$settings, 'show']
    );
});
