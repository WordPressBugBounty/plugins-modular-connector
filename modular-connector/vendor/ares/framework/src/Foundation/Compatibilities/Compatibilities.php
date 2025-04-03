<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Compatibilities;

class Compatibilities
{
    public static function getCompatibilityFixes()
    {
        return [WPForms::class, AllInOneSecurity::class, WpSimpleFirewall::class, DuoFactor::class, ShieldUserManagementICWP::class, SidekickPlugin::class, SpamShield::class, WPO365Login::class, JetPlugins::class, WPEngine::class, LoginLockdown::class, Office365forPostSMTPExtension::class];
    }
}
