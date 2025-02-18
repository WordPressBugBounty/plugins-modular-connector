<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Compatibilities;

class DuoFactor
{
    public static function fix()
    {
        if (!class_exists('Modular\ConnectorDependencies\Duo')) {
            return;
        }
        add_action('init', function () {
            remove_action('init', 'duo_verify_auth', 10);
        }, -1);
    }
}
