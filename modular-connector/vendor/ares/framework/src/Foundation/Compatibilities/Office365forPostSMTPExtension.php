<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Compatibilities;

class Office365forPostSMTPExtension
{
    public static function fix()
    {
        add_action('plugins_loaded', function () {
            if (!class_exists('Post_Smtp_Office365')) {
                return;
            }
            // Disabled "Authorization code should be in the "code" query param"
            self::removeFilterByClassName('post_smtp_handle_oauth', \Post_Smtp_Office365::class, 'handle_oauth');
            remove_action('post_smtp_handle_oauth', 'Post_Smtp_Office365::handle_oauth');
        });
    }
    /**
     * @param string $hookName
     * @param string $className
     * @param string $methodName
     * @param int $priority
     * @return false
     */
    protected static function removeFilterByClassName(string $hookName, string $className, string $methodName, int $priority = 10)
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
            if (!is_object($object) || get_class($object) !== $className || $method !== $methodName) {
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
