<?php
/**
 * Plugin Name: Modular Connector
 * Plugin URI: https://modulards.com/herramienta-gestion-webs/
 * Description: Connect and manage all your WordPress websites in an easier and more efficient way. Backups, bulk updates, Uptime Monitor, statistics, security, performance, client reports and much more.
 * Version: 2.3.0
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

define('MODULAR_CONNECTOR_BASENAME', sprintf('%s/%s', basename(dirname(__FILE__)), basename(__FILE__)));
define('MODULAR_CONNECTOR_MU_BASENAME', sprintf('0-%s.php', dirname(MODULAR_CONNECTOR_BASENAME)));
define('MODULAR_CONNECTOR_VERSION', '2.3.0');
define('MODULAR_ARES_SCHEDULE_HOOK', 'modular_connector_run_schedule');
define('MODULAR_CONNECTOR_STORAGE_PATH', untrailingslashit(WP_CONTENT_DIR) . DIRECTORY_SEPARATOR . 'modular_storage');
define('MODULAR_CONNECTOR_BACKUPS_PATH', untrailingslashit(WP_CONTENT_DIR) . DIRECTORY_SEPARATOR . 'modular_backups');

if (!defined('MODULAR_CONNECTOR_LOG_LEVEL')) {
    define('MODULAR_CONNECTOR_LOG_LEVEL', 'error');
}

$autoload = __DIR__ . '/src/bootstrap/autoload.php';

if (file_exists($autoload)) {
    require_once $autoload;

    if (function_exists('register_deactivation_hook')) {
        register_deactivation_hook(__FILE__, [\Modular\Connector\Facades\Manager::class, 'deactivate']);
    }

    if (function_exists('register_uninstall_hook')) {
        register_uninstall_hook(__FILE__, [\Modular\Connector\Services\Manager::class, 'uninstall']);
    }

    \Modular\Connector\WordPress\Admin::setup();
}
