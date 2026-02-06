<?php

namespace Modular\Connector\WordPress;

use Modular\ConnectorDependencies\Ares\Framework\Foundation\Http\HttpUtils;

class ModularPluginUpgrader extends \Plugin_Upgrader
{
    public function maintenance_mode($enable = \false)
    {
        global $wp_filesystem;

        if (!$wp_filesystem) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }

        $file = $wp_filesystem->abspath() . '.maintenance';

        if ($enable) {
            if (!wp_doing_cron()) {
                $this->skin->feedback('maintenance_start');
            }

            // Create maintenance file with strict validation for Modular communications
            // Uses centralized logic from HttpUtils::generateMaintenance()
            $maintenanceString = HttpUtils::generateMaintenance();

            $wp_filesystem->delete($file);
            $wp_filesystem->put_contents($file, $maintenanceString, FS_CHMOD_FILE);
        } elseif (!$enable && $wp_filesystem->exists($file)) {
            if (!wp_doing_cron()) {
                $this->skin->feedback('maintenance_end');
            }
            $wp_filesystem->delete($file);
        }
    }
}