<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Compatibilities;

use DateTime;
class AllInOneSecurity
{
    public static function fix()
    {
        if (!class_exists('Modular\ConnectorDependencies\AIO_WP_Security')) {
            return;
        }
        add_action('init', function () {
            $user = wp_get_current_user();
            if (empty($user->ID)) {
                return;
            }
            $current_time = new DateTime('@' . current_time('timestamp'));
            update_user_meta($user->ID, 'last_login_time', $current_time->format('Y-m-d H:i:s'));
        }, -1);
    }
}
