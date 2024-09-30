<?php

namespace Modular\Connector\Services\Manager;

use Modular\Connector\Facades\Server;
use Modular\Connector\Services\Backup\BackupOptions;
use Modular\Connector\Services\Backup\Dumper\PHPDumper;
use Modular\Connector\Services\Backup\Dumper\ShellDumper;
use Modular\ConnectorDependencies\Illuminate\Support\Collection;
use Modular\ConnectorDependencies\Illuminate\Support\Str;

/**
 * Handles all functionality related to WordPress database.
 */
class ManagerDatabase
{
    const NAME = 'database';

    /**
     * Get what is the current database extension used by WP
     *
     * @return string|null
     */
    public function extension()
    {
        global $wpdb;

        // Unknown sql extension.
        $extension = null;

        // Populate the database debug fields.
        if (is_resource($wpdb->dbh)) {
            // Old mysql extension.
            $extension = 'mysql';
        } elseif (is_object($wpdb->dbh)) {
            // mysqli or PDO.
            $extension = get_class($wpdb->dbh);
        }

        return $extension;
    }

    /**
     * Get database version.
     *
     * @return string|null
     */
    public function server()
    {
        global $wpdb;

        return $wpdb->db_version();
    }

    /**
     * Get database engine.
     *
     * @return string
     */
    public function engine()
    {
        global $wpdb;

        $mysql_server_type = $wpdb->db_server_info();

        return stristr($mysql_server_type, 'mariadb') ? 'MariaDB' : 'MySQL';
    }

    /**
     * @return string|null
     */
    public function clientVersion()
    {
        global $wpdb;

        $version = null;

        if (isset($wpdb->use_mysqli) && $wpdb->use_mysqli) {
            $version = $wpdb->dbh->client_info;
        } else if (
            function_exists('mysql_get_client_info') &&
            preg_match('|[0-9]{1,2}\.[0-9]{1,2}\.[0-9]{1,2}|', mysql_get_client_info(), $matches)
        ) {
            $version = $matches[0];
        }

        return $version;
    }

    /**
     * Get database information
     *
     * @return array
     */
    public function get()
    {
        return [
            'extension' => $this->extension(),
            'server' => $this->server(),
            'engine' => $this->engine(),
            'client_version' => $this->clientVersion(),
        ];
    }

    /**
     * @param array $excludedTables
     * @return array
     */
    public function getExcludedTables(array $excludedTables): array
    {
        $excludedTables = array_merge($excludedTables, $this->views());

        return $this->tree()
            ->filter(
                fn($table) => in_array($table->path, $excludedTables) || in_array($table->name, $excludedTables)
            )
            ->values()
            ->toArray();
    }

    /**
     * Get database tree
     *
     * @return Collection
     */
    public function tree(): Collection
    {
        global $wpdb;

        $tables = $wpdb->get_results(
            $wpdb->prepare("SELECT table_name AS name, data_length + index_length as size FROM information_schema.TABLES WHERE table_schema = %s", DB_NAME)
        );

        return Collection::make($tables)
            ->map(function ($table) use ($wpdb) {
                $hasPrefix = Str::startsWith($table->name, $wpdb->prefix);

                $table->prefix = $hasPrefix ? $wpdb->prefix : '';
                $table->path = $hasPrefix ? Str::replace($wpdb->prefix, '', $table->name) : $table->name;

                return $table;
            });
    }

    /**
     * Get database tree
     *
     * @return array
     */
    public function views(): array
    {
        global $wpdb;

        $tables = $wpdb->get_results(
            $wpdb->prepare("SELECT table_name AS name FROM information_schema.TABLES WHERE table_schema = %s and Table_Type = 'VIEW'", DB_NAME)
        );

        return Collection::make($tables)
            ->map(fn($table) => $table->name)
            ->toArray();
    }

    /**
     * Upgrade database after core upgrade
     *
     * @return array
     */
    public function upgrade(): array
    {
        include_once ABSPATH . 'wp-admin/includes/upgrade.php';

        @wp_upgrade();

        return [
            'item' => 'database',
            'success' => true,
            'response' => true,
        ];;
    }

    /**
     * Create database dump
     *
     * @param string $path
     * @param BackupOptions $options
     * @return void
     * @throws \Exception
     */
    public function dump(string $path, BackupOptions $options)
    {
        $excluded = $options->excludedTables;

        // TODO implement multi driver support
        if (Server::shellIsAvailable()) {
            try {
                ShellDumper::dump($path, $options->connection, $excluded);
                return;
            } catch (\Throwable $e) {
                // silence is golden
            }
        }

        // If shell dumper failed, try PHP dumper
        PHPDumper::dump($path, $options->connection, $excluded);
    }
}
