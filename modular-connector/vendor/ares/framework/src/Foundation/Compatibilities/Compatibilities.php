<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Compatibilities;

class Compatibilities
{
    public static function getCompatibilityFixes()
    {
        return [
            // Regular plugins
            AllInOneSecurity::class,
            WpSimpleFirewall::class,
            DuoFactor::class,
            ShieldUserManagementICWP::class,
            SidekickPlugin::class,
            SpamShield::class,
            WPO365Login::class,
        ];
    }
}
