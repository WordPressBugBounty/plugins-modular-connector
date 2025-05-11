<?php

namespace Modular\Connector\WordPress;

use Modular\Connector\Helper\OauthClient;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Http\HttpUtils;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Cache;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\View;
use function Modular\ConnectorDependencies\data_get;

class Admin
{
    /**
     * Sets up all the necessary WordPress functionality on the admin panel
     */
    public static function setup()
    {
        add_action('admin_enqueue_scripts', [self::class, 'addStyles']);
        add_action('admin_menu', [self::class, 'addMenu']);
        add_action('admin_notices', [self::class, 'addAdminNotice']);
        add_action('template_redirect', [self::class, 'addMaintenanceMode'], 99);

    }

    /**
     * Enqueues all styles needed for the modular menu on the admin panel
     */
    public static function addStyles()
    {
        wp_enqueue_style('modular-connector-styles', plugin_dir_url(MODULAR_CONNECTOR_BASENAME) . '/src/resources/css/index.css', [], MODULAR_CONNECTOR_VERSION);
    }

    /**
     * Adds the ModularDS connection manager menu on the admin panel
     */
    public static function addMenu()
    {
        $settings = new Settings();

        add_management_page(
            $settings->title(),
            $settings->title(),
            'manage_options',
            $settings->slug,
            [$settings, 'show']
        );
    }

    /**
     * Adds a ModularDS admin notice in case plugin needs upgrading or has not been connected
     */
    public static function addAdminNotice()
    {
        $connection = OauthClient::getClient();

        if ((!is_multisite() || is_main_site()) && empty($connection->getClientId())) {
            echo View::make('notices.disconnected');
        } elseif (!empty($connection->getClientId()) && empty($connection->getConnectedAt())) {
            echo View::make('notices.pending');
        }
    }

    /**
     * Adds a ModularDS maintenance page if the corresponding option is enabled
     */
    public static function addMaintenanceMode($template)
    {
        $data = Cache::driver('wordpress')->get('maintenance_mode');
        $enabled = data_get($data, 'enabled', false) ?: false;
        $enabled = $enabled && !current_user_can('manage_options') && !empty(OauthClient::getClient()->getConnectedAt());

        if ($enabled && !HttpUtils::isAjax() && !HttpUtils::isCron() && !HttpUtils::isDirectRequest()) {
            if (!headers_sent()) {
                header('Retry-After: 600');
                header("Content-Type: text/html; charset=utf-8");
                status_header(503);
                nocache_headers();
            }

            $title = data_get($data, 'title') ?: esc_html__('Website Under Maintenance', 'modular-connector');
            $description = data_get($data, 'description') ?: esc_html__('We are performing scheduled maintenance work. We should be back online shortly.', 'modular-connector');
            $withBranding = data_get($data, 'withBranding', true);
            $background = data_get($data, 'background') ?: '#6308F7';
            $noindex = data_get($data, 'noindex') ?: false;

            echo View::make('parts.maintenance', compact('title', 'description', 'withBranding', 'background', 'noindex'));
            die();
        }

        return $template;
    }
}
