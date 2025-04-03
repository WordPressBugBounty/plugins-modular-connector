<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Compatibilities;

use Modular\ConnectorDependencies\Carbon\Carbon;
class AllInOneSecurity
{
    public static function fix()
    {
        add_action('plugins_loaded', function () {
            if (!class_exists('AIO_WP_Security')) {
                return;
            }
            add_action('init', function () {
                $user = wp_get_current_user();
                if (empty($user->ID)) {
                    return;
                }
                update_user_meta($user->ID, 'aiowps_last_login_time', Carbon::now()->format('Y-m-d H:i:s'));
            }, -1);
        });
    }
}
