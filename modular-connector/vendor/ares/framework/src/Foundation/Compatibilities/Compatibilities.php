<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Compatibilities;

class Compatibilities
{
    public static function getCompatibilityFixes()
    {
        return [WPForms::class, AllInOneSecurity::class, WpSimpleFirewall::class, DuoFactor::class, ShieldUserManagementICWP::class, SidekickPlugin::class, SpamShield::class, WPO365Login::class, JetPlugins::class, WPEngine::class, LoginLockdown::class, Office365forPostSMTPExtension::class, ConstantContactForms::class, Wp2Fa::class, ShieldSecurity::class, Translatepress::class];
    }
    /**
     * @param string $hookName
     * @param string $className
     * @param string $methodName
     * @param int $priority
     * @return false
     */
    public static function removeFilterByClassName(string $hookName, string $className, string $methodName, int $priority = 10)
    {
        global $wp_filter;
        if (empty($wp_filter[$hookName])) {
            return \false;
        }
        // Check if the hook is a WP_Hook object
        $isWpHook = is_a($wp_filter[$hookName], 'WP_Hook');
        // Extract the filters
        if ($isWpHook) {
            $hooks = $wp_filter[$hookName]->callbacks[$priority] ?? [];
        } else {
            $hooks = $wp_filter[$hookName][$priority] ?? [];
        }
        if (empty($hooks) || !is_array($hooks)) {
            return \false;
        }
        // Loop through the filters
        foreach ($hooks as $uniqueId => $filter) {
            if (!isset($filter['function']) || !is_array($filter['function'])) {
                continue;
            }
            // Extract the object and method
            $object = $filter['function'][0] ?? null;
            $method = $filter['function'][1] ?? null;
            $isClassMatch = is_object($object) && get_class($object) === $className || is_string($object) && $object === $className;
            if (!$isClassMatch || $method !== $methodName) {
                continue;
            }
            // Remove the filter
            if ($isWpHook) {
                unset($wp_filter[$hookName]->callbacks[$priority][$uniqueId]);
            } else {
                unset($wp_filter[$hookName][$priority][$uniqueId]);
            }
        }
        return \true;
    }
}
