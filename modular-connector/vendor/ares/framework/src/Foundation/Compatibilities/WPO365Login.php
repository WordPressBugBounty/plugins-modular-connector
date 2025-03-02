<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Compatibilities;

class WPO365Login
{
    public static function fix()
    {
        if (!class_exists('\Wpo\Services\Authentication_Service')) {
            return;
        }
        // Disables possible redirect caused by Wpo365's SSO options
        remove_action('init', '\Wpo\Services\Authentication_Service::authenticate_request', 1);
    }
}
