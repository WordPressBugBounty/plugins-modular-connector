<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Compatibilities;

class SidekickPlugin
{
    public static function fix()
    {
        add_action('init', function () {
            if (!class_exists('Sidekick')) {
                return null;
            }
            global $wp_filter;
            if (empty($wp_filter['admin_init'][10])) {
                return null;
            }
            foreach ($wp_filter['admin_init'][10] as $callable) {
                if (empty($callable['function']) || !is_array($callable['function']) || count($callable['function']) < 2) {
                    continue;
                }
                if (!is_a($callable['function'][0], 'Sidekick')) {
                    continue;
                }
                if ($callable['function'][1] !== 'redirect') {
                    continue;
                }
                remove_action('admin_init', $callable['function'], 10);
                return $callable['function'];
            }
            return null;
        }, -1);
    }
}
