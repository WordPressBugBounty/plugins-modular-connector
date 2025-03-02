<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Compatibilities;

class WPForms
{
    public static function fix()
    {
        if (!defined('WPFORMS_PLUGIN_DIR')) {
            return;
        }
        $files = ['pro/includes/admin/class-license.php', 'pro/includes/admin/ajax-actions.php', 'pro/includes/admin/entries/class-entries-single.php', 'pro/includes/admin/class-updater.php'];
        foreach ($files as $file) {
            if (file_exists(\WPFORMS_PLUGIN_DIR . $file)) {
                require_once \WPFORMS_PLUGIN_DIR . $file;
            }
        }
    }
}
