<?php

namespace Modular\Connector\Services\Manager;

use Modular\Connector\Backups\Dumper\PHPDumper;
use Modular\Connector\Backups\Dumper\ShellDumper;
use Modular\Connector\Facades\Server;
use Modular\Connector\Jobs\ConfigureDriversJob;
use Modular\ConnectorDependencies\Illuminate\Support\Collection;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Cache;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use Modular\ConnectorDependencies\Illuminate\Support\Str;
use function Modular\ConnectorDependencies\database_path;
use function Modular\ConnectorDependencies\dispatch;

/**
 * Handles all functionality related to WordPress database.
 */
class ManagerDatabase
{
    public const NAME = 'database';

    private const MODULAR_DB_VERSION = 'modular_db_version';

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
        } elseif (
            function_exists('mysql_get_client_info') &&
            preg_match('|[0-9]{1,2}\.[0-9]{1,2}\.[0-9]{1,2}|', mysql_get_client_info(), $matches)
        ) {
            $version = $matches[0];
        }

        return $version;
    }

    /**
     * @return string
     */
    public function prefix()
    {
        global $wpdb;

        return $wpdb->prefix;
    }

    /**
     * @return string
     */
    public function charset()
    {
        global $wpdb;

        return $wpdb->charset;
    }

    /**
     * @return string
     */
    public function collate()
    {
        global $wpdb;

        return $wpdb->collate;
    }

    /**
     * @param $var
     * @return mixed|null
     */
    public function getMySQLVar($var)
    {
        global $wpdb;

        $result = $wpdb->get_row($wpdb->prepare('SHOW VARIABLES LIKE %s', $var), ARRAY_A);

        if (!empty($result) && array_key_exists('Value', $result)) {
            return $result['Value'];
        }

        return null;
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
            'prefix' => $this->prefix(),
            'charset' => $this->charset(),
            'collate' => $this->collate(),
            'max_allowed_packet_size' => $this->getMySQLVar('max_allowed_packet'),
            'max_connections' => $this->getMySQLVar('max_connections'),
        ];
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
        ];
    }

    /**
     * Upgrade WooCommerce database
     *
     * @return void
     */
    public function upgradeWooCommerce()
    {
        if (!class_exists('WC_Install')) {
            $fileName = WP_PLUGIN_DIR . '/woocommerce/includes/class-wc-install.php';

            if (!file_exists($fileName)) {
                Log::debug("File $fileName doesn't exist");

                return;
            }

            include_once $fileName;
        }

        \WC_Install::run_manual_database_update();
    }

    /**
     * Upgrade Elementor database
     *
     * @return string|void
     */
    public function upgradeElementor(bool $isPro)
    {
        $managerClass = $isPro ? \ElementorPro\Core\Upgrade\Manager::class : \Elementor\Core\Upgrade\Manager::class;

        if (!class_exists($managerClass)) {
            return;
        }

        /** @var \Elementor\Core\Upgrade\Manager $manager */
        $manager = new $managerClass();
        $updater = $manager->get_task_runner();

        try {
            if ($updater->is_process_locked()) {
                Log::debug('Elementor upgrade process is already running.');

                return;
            }

            if (!$manager->should_upgrade()) {
                Log::debug('Elementor database is already upgraded.');

                return;
            }

            $callbacks = $manager->get_upgrade_callbacks();
            $didTasks = false;

            if (!empty($callbacks)) {
                $updater->handle_immediately($callbacks);

                $didTasks = true;
            }

            $manager->on_runner_complete($didTasks);
        } catch (\Throwable $e) {
            Log::error($e);
        }
    }

    /**
     * Create database dump
     *
     * @param string $path
     * @param \Modular\Connector\Backups\Iron\BackupPart|\Modular\Connector\Backups\Phantom\BackupOptions $options
     * @return void
     * @throws \Exception
     */
    public function dump(string $path, $options)
    {
        $excluded = $options->excludedTables;

        // TODO implement multi driver support
        if (Server::shellIsAvailable()) {
            Log::debug('Using shell dumper for database dump');

            try {
                ShellDumper::dump($path, $options->connection, $excluded);
                return;
            } catch (\Throwable $e) {
                // silence is golden
                Log::debug($e);
            }
        }

        Log::debug('Using PHP dumper for database dump');

        // If shell dumper failed, try PHP dumper
        PHPDumper::dump($path, $options->connection, $excluded);
    }

    /**
     * Returns the current database version on the WordPress site
     *
     * @return string
     */
    public function getModularVersion()
    {
        // TODO Remove me after the next release
        $version = get_option('_modular_connector_database_version', '0.0.0');

        if ($version !== '0.0.0') {
            $this->setModularVersion($version);
        }

        return Cache::driver('wordpress')->get(self::MODULAR_DB_VERSION, '0.0.0');
    }

    /**
     * Sets up the database version on the WordPress site
     *
     * @param string $version The version to set
     *
     * @return void
     */
    public function setModularVersion(?string $version)
    {
        if (!is_null($version)) {
            Cache::driver('wordpress')->forever(self::MODULAR_DB_VERSION, $version);
        } else {
            Cache::driver('wordpress')->forget(self::MODULAR_DB_VERSION);
        }

        // TODO Remove me after the next release
        if (get_option('_modular_connector_database_version', false)) {
            delete_option('_modular_connector_database_version');
        }
    }

    /**
     * Loads the database migrations that need to be run
     *
     * @param string|null $newVersion all migrations will be returned if null
     * @return Collection The migrations that need to be run
     */
    private function loadMigrations(?string $newVersion = null): Collection
    {
        $migrations = Collection::make(glob(database_path('migrations/*.php')))
            ->map(fn($migration) => require_once $migration);

        if (empty($newVersion)) {
            return $migrations;
        }

        return $migrations->filter(fn($migration) => version_compare($migration->version, $this->getModularVersion(), '>'));
    }

    /**
     * @return void
     */
    public function migrate()
    {
        $dbVersion = $this->getModularVersion();
        $newDBVersion = MODULAR_CONNECTOR_VERSION;

        // If the database is already up to date, avoid running the migrations
        if ($dbVersion === $newDBVersion) {
            return;
        }

        Log::debug('Migrating database from ' . $dbVersion . ' to ' . $newDBVersion);

        try {
            $migrations = $this->loadMigrations($newDBVersion);
            $migrations->each(fn($migration) => $migration->up());
        } finally {
            // To avoid running the migrations again, we set the new version
            $this->setModularVersion($newDBVersion);

            // After each migration, we need to check if the cache and queue driver is working
            dispatch(new ConfigureDriversJob());
        }
    }

    /**
     * Removes Modular Connector's database tables
     *
     * @return void
     */
    public function rollback()
    {
        $this->loadMigrations()->reverse()->each(fn($migration) => $migration->down());

        $this->setModularVersion(null);
    }
}
