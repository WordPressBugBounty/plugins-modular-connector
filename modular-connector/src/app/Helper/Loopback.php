<?php

namespace Modular\Connector\Helper;

use Modular\ConnectorDependencies\Ares\Framework\Foundation\Auth\JWT;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Cache;
use function Modular\ConnectorDependencies\app;

/**
 * Builds the signed loopback request (`wp-load.php?origin=mo&type=lb`) the plugin sends to
 * itself. The scheduler kick after each response and the health probes that need to observe
 * a request from the inside share the same URL, nonce and JWT.
 */
class Loopback
{
    /**
     * Seconds a loopback JWT stays valid.
     */
    private const JWT_TTL = 300;

    /**
     * @return string
     */
    public static function nonce(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Loopback URL carrying the nonce, after the `{hook}_query_url` / `{hook}_query_args` filters.
     *
     * @param string $nonce
     * @return string
     */
    public static function url(string $nonce): string
    {
        $hook = app()->getScheduleHook();

        $url = apply_filters(sprintf('%s_query_url', $hook), site_url('wp-load.php'));

        $query = apply_filters(sprintf('%s_query_args', $hook), [
            'origin' => 'mo',
            'type' => 'lb',
            'lbn' => $nonce,
        ]);

        return esc_url_raw(add_query_arg($query, $url));
    }

    /**
     * `wp_remote_get()` arguments for the loopback, or null when the site holds no client
     * credentials to sign it with.
     *
     * @param string $nonce The one embedded in the URL: the JWT carries it too.
     * @param array $claims Extra JWT claims, e.g. a health probe marker.
     * @param bool $blocking
     * @return array|null
     */
    public static function args(string $nonce, array $claims = [], bool $blocking = false): ?array
    {
        $client = OauthClient::getClient();
        $clientSecret = $client->getClientSecret();
        $clientId = $client->getClientId();

        if (empty($clientSecret) || empty($clientId)) {
            return null;
        }

        $args = [
            'timeout' => 10, // In some websites, the default value of 5 seconds is too short.
            'sslverify' => false,
            'blocking' => $blocking,
            'headers' => [
                'User-Agent' => 'ModularConnector/' . MODULAR_CONNECTOR_VERSION . ' (Linux)',
                'x-mo-authentication' => 'Bearer ' . static::jwt($clientSecret, $clientId, $nonce, $claims),
            ],
        ];

        // Sites behind HTTP basic auth stored the credentials the loopback must present.
        $authorization = Cache::driver('wordpress')->get('header.authorization');

        if ($authorization) {
            $args['headers']['Authorization'] = $authorization;
        }

        return apply_filters(sprintf('%s_post_args', app()->getScheduleHook()), $args);
    }

    /**
     * HS256 JWT signed with the client secret, bound to this site and to the nonce.
     *
     * @param string $clientSecret
     * @param string $clientId
     * @param string $nonce
     * @param array $claims
     * @return string
     */
    public static function jwt(string $clientSecret, string $clientId, string $nonce, array $claims = []): string
    {
        $header = [
            'typ' => 'JWT',
            'alg' => 'HS256',
        ];

        $payload = array_merge($claims, [
            'iat' => time(),
            'exp' => time() + self::JWT_TTL,
            'type' => 'loopback',
            'client_id' => $clientId, // Site-specific verification
            'lbn' => $nonce, // Must match the query string lbn parameter
        ]);

        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

        $base64UrlHeader = JWT::base64UrlEncode(json_encode($header, $flags));
        $base64UrlPayload = JWT::base64UrlEncode(json_encode($payload, $flags));

        $signature = hash_hmac('sha256', "{$base64UrlHeader}.{$base64UrlPayload}", $clientSecret, true);

        return "{$base64UrlHeader}.{$base64UrlPayload}." . JWT::base64UrlEncode($signature);
    }
}
