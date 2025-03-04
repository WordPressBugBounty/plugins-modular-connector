<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Compatibilities;

class JetPlugins
{
    public static function fix()
    {
        // Check if Jet Plugins Wizard is installed
        if (!function_exists('jet_plugins_wizard')) {
            return;
        }
        if (!function_exists('jet_plugins_wizard_settings')) {
            new \Jet_Plugins_Wizard();
        }
    }
}
