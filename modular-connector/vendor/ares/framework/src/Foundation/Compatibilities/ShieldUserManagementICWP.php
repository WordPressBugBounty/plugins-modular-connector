<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Compatibilities;

class ShieldUserManagementICWP
{
    public static function fix()
    {
        add_filter('icwp-wpsf-visitor_is_whitelisted', '__return_true');
    }
}
