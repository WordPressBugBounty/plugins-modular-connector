<?php

namespace Modular\Connector\Helper;

use Modular\SDK\ModularClient;
use function Modular\ConnectorDependencies\data_get;

class OauthClient
{
    /**
     * Generate Modular Key Connection
     *
     * @return ModularClient
     */
    public static function getClient(): ModularClient
    {
        $value = [
            'client_id' => get_option('_modular_connection_client_id', null),
            'client_secret' => get_option('_modular_connection_client_secret', null),
            'access_token' => get_option('_modular_connection_access_token', null),
            'refresh_token' => get_option('_modular_connection_refresh_token', null),
            'connected_at' => get_option('_modular_connection_connected_at', null),
            'used_at' => get_option('_modular_connection_used_at', null),
            'expires_in' => get_option('_modular_connection_expires_in', null),
        ];

        return static::mapClient($value);
    }

    /**
     * Retrieves the URL for a given site where the front end is accessible.
     *
     * Returns the 'home' option with the appropriate protocol. The protocol will be 'https'
     * if is_ssl() evaluates to true; otherwise, it will be the same as the 'home' option.
     * If `$scheme` is 'http' or 'https', is_ssl() is overridden.
     *
     * @param string $path Optional. Path relative to the home URL. Default empty.
     * @param string|null $scheme Optional. Scheme to give the home URL context. Accepts
     *                             'http', 'https', 'relative', 'rest', or null. Default null.
     * @return string Home URL link with optional path appended.
     * @since 3.0.0
     */
    public static function getHomeUrl($path = '', $scheme = null)
    {
        remove_filter('home_url', 'add_language_to_home_url', 1);

        return home_url($path, $scheme);
    }

    /**
     * @param array $client
     * @return ModularClient
     */
    public static function mapClient(array $client)
    {
        return new ModularClient([
            'env' => defined('MODULAR_CONNECTOR_ENV') ? MODULAR_CONNECTOR_ENV : null,
            'oauth_client' => [
                'client_id' => data_get($client, 'client_id'),
                'client_secret' => data_get($client, 'client_secret'),
                'connected_at' => data_get($client, 'connected_at'),
                'used_at' => data_get($client, 'used_at'),
                'redirect_uri' => self::getHomeUrl('/'),
            ],
            'oauth_token' => [
                'expires_in' => data_get($client, 'expires_in'),
                'access_token' => data_get($client, 'access_token'),
                'refresh_token' => data_get($client, 'refresh_token'),
            ],
        ]);
    }

    /**
     * Check if the site was registered via linking token.
     *
     * @return bool
     */
    public static function isLinkingRegistered(): bool
    {
        return (bool)get_option('_modular_linking_registered', false);
    }

    /**
     * Mark the site as registered via linking token.
     *
     * @return void
     */
    public static function setLinkingRegistered(): void
    {
        update_option('_modular_linking_registered', true);
    }

    /**
     * Mark the site as registered via linking token.
     *
     * @return void
     */
    public static function removeLinkingRegistered(): void
    {
        delete_option('_modular_linking_registered');
    }

    /**
     * Deletes all stored clients
     */
    public static function uninstall()
    {
        delete_option('_modular_connection_client_id');
        delete_option('_modular_connection_client_secret');
        delete_option('_modular_connection_access_token');
        delete_option('_modular_connection_refresh_token');
        delete_option('_modular_connection_connected_at');
        delete_option('_modular_connection_used_at');
        delete_option('_modular_connection_expires_in');

        static::removeLinkingRegistered();
    }
}
