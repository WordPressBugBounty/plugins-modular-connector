<?php

namespace Modular\Connector\database\migrations;

use Modular\ConnectorDependencies\Illuminate\Database\Migrations\Migration;

/**
 * Normalize permissions on the modular_storage subfolders.
 */
return new class extends Migration {
    /**
     * @var string
     */
    public string $version = '3.0.0';

    /**
     * @var string[]
     */
    public array $tables = [];

    public function up()
    {
        if (!defined('MODULAR_CONNECTOR_STORAGE_PATH')) {
            return;
        }

        $subFolders = ['views', 'logs', 'cache', 'upgrades', 'scans'];

        foreach ($subFolders as $name) {
            $path = MODULAR_CONNECTOR_STORAGE_PATH . DIRECTORY_SEPARATOR . $name;

            if (!is_dir($path)) {
                continue;
            }

            $perms = @fileperms($path);
            if ($perms === false) {
                continue;
            }

            // fileperms returns the inode mode; mask to the permission bits.
            if (($perms & 0777) === 0755) {
                continue;
            }

            @chmod($path, 0755);
        }
    }

    public function down()
    {
        // Permission tightening is not reverted on rollback.
    }
};
