<?php

namespace Modular\Connector\Health\Tests;

use Modular\Connector\Health\AbstractHealthTest;

class BackgroundUpdatesTest extends AbstractHealthTest
{
    /**
     * @return string
     */
    public function identifier()
    {
        return 'background_updates';
    }

    /**
     * @return array
     */
    public function run()
    {
        // wp-admin/includes is not autoloaded in cron / REST contexts.
        if (!class_exists('WP_Site_Health_Auto_Updates')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-site-health-auto-updates.php';
        }

        // run_tests() instantiates Automatic_Upgrader_Skin and Core_Upgrader,
        // which fatal without these requires outside admin.
        $this->ensureUpgraderClassesLoaded();

        $autoUpdates = new \WP_Site_Health_Auto_Updates();
        $tests = $autoUpdates->run_tests();

        $checks = [];
        $worstStatus = 'good';

        foreach ($tests as $test) {
            $status = 'good';

            if ($test->severity === 'fail') {
                $status = 'critical';
                $worstStatus = 'critical';
            } elseif ($test->severity === 'warning') {
                $status = 'recommended';

                if ($worstStatus !== 'critical') {
                    $worstStatus = 'recommended';
                }
            }

            $checks[] = [
                'description' => $test->description,
                'status' => $status,
            ];
        }

        return $worstStatus === 'good'
            ? $this->result('good')
            : $this->result($worstStatus, ['checks' => $checks]);
    }

    /**
     * @return void
     */
    private function ensureUpgraderClassesLoaded()
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    }
}
