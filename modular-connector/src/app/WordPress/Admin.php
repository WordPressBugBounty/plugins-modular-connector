<?php

namespace Modular\Connector\WordPress;

use Modular\Connector\Helper\OauthClient;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\View;

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
}
