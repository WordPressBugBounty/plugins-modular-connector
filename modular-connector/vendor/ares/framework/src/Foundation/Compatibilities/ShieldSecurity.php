<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Compatibilities;

class ShieldSecurity
{
    public static function fix()
    {
        add_action('plugin_loaded', function ($plugin) {
            $toSearch = 'wp-simple-firewall/icwp-wpsf.php';
            if (is_string($plugin) && strpos($plugin, $toSearch) === \false) {
                return;
            }
            remove_action('plugins_loaded', 'icwp_wpsf_init', 1);
        }, 1);
    }
}
