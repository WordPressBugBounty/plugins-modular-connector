<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Compatibilities;

class SpamShield
{
    public static function fix()
    {
        // Fix needed undefined constant
        if (!defined('WPSS_IP_BAN_CLEAR')) {
            define('WPSS_IP_BAN_CLEAR', \true);
        }
        // Fix spam shield ban
        $wpssUblCache = get_option('spamshield_ubl_cache');
        if (empty($wpssUblCache)) {
            return;
        }
        $serverIp = !empty($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null;
        foreach ($wpssUblCache as $key => $singleIp) {
            if ($singleIp !== $serverIp) {
                continue;
            }
            unset($wpssUblCache[$key]);
        }
        update_option('spamshield_ubl_cache', array_values($wpssUblCache));
    }
}
