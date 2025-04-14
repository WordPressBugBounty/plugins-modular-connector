<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Compatibilities;

class ConstantContactForms
{
    public static function fix()
    {
        add_action('plugins_loaded', function () {
            if (!class_exists('ConstantContact_Connect')) {
                return;
            }
            Compatibilities::removeFilterByClassName('init', \ConstantContact_Connect::class, 'maybe_connect');
        });
    }
}
