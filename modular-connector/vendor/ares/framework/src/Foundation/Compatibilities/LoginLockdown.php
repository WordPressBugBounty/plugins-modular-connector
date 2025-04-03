<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Compatibilities;

class LoginLockdown
{
    public static function fix()
    {
        add_action('plugins_loaded', function () {
            if (!class_exists('loginlockdown')) {
                return;
            }
            remove_action('init', 'loginlockdown::init', -1);
        });
    }
}
