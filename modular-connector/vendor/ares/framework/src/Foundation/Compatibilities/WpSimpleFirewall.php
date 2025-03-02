<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Compatibilities;

class WpSimpleFirewall
{
    public static function fix()
    {
        if (!class_exists('ICWP_WPSF_Shield_Security')) {
            return;
        }
        includeWPSFClassIfNeeded();
    }
}
function includeWPSFClassIfNeeded()
{
    if (class_exists('ICWP_WPSF_Processor_LoginProtect_TwoFactorAuth', \false)) {
        return;
    }
    class ICWP_WPSF_Processor_LoginProtect_TwoFactorAuth
    {
        public function run()
        {
        }
    }
}
