<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Compatibilities;

class Translatepress
{
    public static function fix()
    {
        add_action('plugins_loaded', function () {
            if (!class_exists('\TRP_Trigger_Plugin_Notifications')) {
                return;
            }
            Compatibilities::removeFilterByClassName('admin_init', \TRP_Trigger_Plugin_Notifications::class, 'add_plugin_notifications');
        }, \PHP_INT_MAX);
    }
}
