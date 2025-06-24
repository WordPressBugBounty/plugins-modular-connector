<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Compatibilities;

use Modular\ConnectorDependencies\Carbon\Carbon;
class Wp2Fa
{
    public static function fix()
    {
        add_action('plugins_loaded', function () {
            if (!class_exists('\WP2FA\WP2FA') || !defined('WP_2FA_PREFIX')) {
                return;
            }
            Compatibilities::removeFilterByClassName('init', \WP2FA\WP2FA::class, 'block_unconfigured_users_from_admin');
            add_action('init', function () {
                $user = wp_get_current_user();
                if (empty($user->ID)) {
                    return;
                }
                update_user_meta($user->ID, \WP_2FA_PREFIX . 'enforcement_state', \true);
                update_user_meta($user->ID, \WP_2FA_PREFIX . 'grace_period_expiry', Carbon::now()->addMinutes(30)->timestamp);
            }, -1);
        }, \PHP_INT_MAX);
    }
}
