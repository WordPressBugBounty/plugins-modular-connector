<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Compatibilities;

use Modular\ConnectorDependencies\Symfony\Component\HttpFoundation\Cookie;
class WPEngine
{
    public static function fix()
    {
        if (!defined('WPE_APIKEY')) {
            return;
        }
        add_filter('ares/login/match', function ($cookies, $userId, $isSecure) {
            $value = hash('sha256', 'wpe_auth_salty_dog|' . \WPE_APIKEY);
            $expires = 0;
            // 0 means the cookie will expire when the browser is closed.
            $cookies[] = new Cookie('wpe-auth', $value, $expires, '/', '', $isSecure, \true);
            return $cookies;
        }, 10, 3);
    }
}
