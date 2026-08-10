<?php

namespace Modular\Connector\Services\Manager;

use Modular\Connector\Backups\Dumper\ChunkedDumper;
use Modular\Connector\Backups\Dumper\PHPDumper;
use Modular\Connector\Backups\Dumper\ShellDumper;
use Modular\Connector\Backups\Iron\BackupPart;
use Modular\Connector\Facades\Server;
use Modular\Connector\Jobs\ConfigureDriversJob;
use Modular\ConnectorDependencies\Illuminate\Support\Collection;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Cache;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Config;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\DB;
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
    public function serverInfo()
    {
        global $wpdb;

        $q = 'SELECT VERSION() AS version';

        try {
            return DB::selectOne($q)->version;
        } catch (\Throwable $e) {
            return $wpdb->get_var($q);
        }
    }

    /**
     * Get database version.
     *
     * @return string|null
     */
    public function server()
    {
        $version = $this->serverInfo();

        if (empty($version)) {
            return '';
        }

        // MariaDB 10+ reports itself as "5.5.5-10.11.6-MariaDB" so that old MySQL clients do
        // not choke on a major version of 10. Left alone, every such server looks like 5.5.5.
        if (Str::startsWith($version, '5.5.5-')) {
            $version = Str::after($version, '5.5.5-');
        }

        return preg_replace('/[^0-9.].*/', '', $version);
    }

    /**
     * Get database engine.
     *
     * @return string
     */
    public function engine()
    {
        $mysql_server_type = $this->serverInfo();

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
            'dump' => $this->dumpCapabilities(),
        ];
    }

    /**
     * What this host can actually dump with, probed live. Reported through
     * manager.server.information (so the API can measure, fleet-wide, e.g. how many sites
     * have a shell but no working dump binary) and consumed by planDump() so the backup
     * takes exactly the path the API saw.
     *
     * shell    → proc_* functions available
     * supported→ PHP >= 8.0, required by spatie/db-dumper 3.x
     * preferred→ binary this engine/version should use
     * binary   → binary that actually answered `--version`, null = PHP dumper path
     *
     * @return array
     */
    public function dumpCapabilities(): array
    {
        $shell = Server::shellIsAvailable();
        $supported = static::supportsShellDumper();

        return [
            'shell' => $shell,
            'supported' => $supported,
            'preferred' => ShellDumper::preferredBinary(),
            'binary' => $shell && $supported ? ShellDumper::detectBinary() : null,
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
            $wpdb->prepare("SELECT table_name AS name, data_length + index_length as size, avg_row_length as avg_row, table_rows as `rows` FROM information_schema.TABLES WHERE table_schema = %s", DB_NAME)
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
     * Resolve the dump plan once, on the part itself: which binary (if any) and which
     * tables are large enough to deserve their own resumable segment.
     *
     * @param \Modular\Connector\Backups\Iron\BackupPart $part
     * @return void
     */
    public function planDump($part): void
    {
        // Probed once and stored on the part, so no later tick pays the try/catch dance or
        // takes a different path than the tick before it.
        $part->dumpBinary = $this->dumpCapabilities()['binary'];

        $excluded = $part->excludedTables;

        $part->dumpTables = $this->tree()
            ->filter(fn($table) => !in_array($table->name, $excluded) && (int)$table->size >= $part->dumpLargeTableSize)
            ->sortBy('name')
            ->values()
            ->map(fn($table) => [
                'name' => $table->name,
                'size' => (int)$table->size,
                'rows' => (int)$table->rows,
                'avgRow' => (int)$table->avg_row,
            ])
            ->toArray();

        $part->dumpTableIndex = 0;
        $part->dumpCursor = [];

        // One segment for every small table plus one per large table: reusing the part's
        // generic progress fields keeps isDone() and the progress webhooks working untouched.
        $part->totalItems = 1 + count($part->dumpTables);
        $part->offset = 0;

        $part->dumpPhase = BackupPart::DUMP_PHASE_SMALL;

        Log::debug('Database dump planned', [
            'binary' => $part->dumpBinary,
            'largeTables' => count($part->dumpTables),
            'threshold' => $part->dumpLargeTableSize,
        ]);
    }

    /**
     * One-shot dump of a set of tables through whichever engine the plan resolved.
     *
     * @param string $path
     * @param \Modular\Connector\Backups\Iron\BackupPart $part
     * @param array|null $only dump only these tables; null dumps everything except the
     *                         excluded ones and the planned large tables
     * @return void
     * @throws \Throwable
     */
    public function dumpSegment(string $path, $part, ?array $only = null): void
    {
        $excluded = $only !== null
            ? []
            : array_values(array_unique(array_merge(
                $part->excludedTables,
                array_column($part->dumpTables, 'name')
            )));

        if ($part->dumpBinary !== null) {
            try {
                ShellDumper::dump($path, $part->connection, $excluded, $only, $part->dumpBinary);

                return;
            } catch (\Throwable $e) {
                if (!static::causedByUnusableBinary($e)) {
                    throw $e;
                }

                // The binary cannot work on this host at all — wrong socket, rejected flag,
                // no grants for the CLI. Retrying it segment after segment would fail the
                // whole backup, so the part switches to the PHP dumper for good; dumpBinary
                // rides on the part, so the API sees the downgrade in `database_plan`.
                Log::warning('Shell dumper cannot run on this host, falling back to the PHP dumper', [
                    'binary' => $part->dumpBinary,
                    'error' => $e->getMessage(),
                ]);

                $part->dumpBinary = null;
            }
        }

        $dumper = PHPDumper::create()
            ->setHost($part->connection['host'])
            ->setPort($part->connection['port'])
            ->setSocket($part->connection['socket'])
            ->setDatabase($part->connection['database'])
            ->setUsername($part->connection['username'])
            ->setPassword($part->connection['password']);

        if ($only !== null) {
            $dumper->includeTables($only);
        } else {
            $dumper->excludeTables($excluded);
        }

        $dumper->dumpToFile($path);
    }

    /**
     * Whether the dump binary failed because it cannot operate on this host, rather than
     * because of the data it was asked to dump.
     *
     * These are environment faults the PHP dumper does not share: it connects through PDO
     * with PHP's own socket and grants, and speaks no command-line flags. A dump that fails
     * for any other reason (a corrupt table, a full disk, a killed connection mid-stream)
     * would fail the same way there, so it is left to fail loudly.
     *
     * @param \Throwable $e
     * @return bool
     */
    private static function causedByUnusableBinary(\Throwable $e): bool
    {
        return Str::contains($e->getMessage(), [
            "Can't connect to",
            'Access denied for user',
            'unknown variable',
            'unknown option',
            'command not found',
            'No such file or directory',
        ]);
    }

    /**
     * @param \Modular\Connector\Backups\Iron\BackupPart $part
     * @return ChunkedDumper
     */
    public function chunkedDumper($part): ChunkedDumper
    {
        return ChunkedDumper::create($part->connection, $this->dumpCharset());
    }

    /**
     * Charset WordPress is actually connected with, read from the live connection so the
     * dumpers never spend a probe of their own.
     *
     * @return string
     */
    private function dumpCharset(): string
    {
        global $wpdb;

        $charset = !empty($wpdb->charset) ? $wpdb->charset : Config::get('database.connections.wordpress.charset');

        return !empty($charset) ? $charset : 'utf8mb4';
    }

    /**
     * Whether this runtime can load the shell dumper at all.
     *
     * `spatie/db-dumper` 3.x declares PHP ^8.0 and uses union types and `static` return
     * types, which are parse errors on 7.4 — its classes cannot even be autoloaded there,
     * so the guard has to sit before anything touches `ShellDumper`.
     *
     * PHP 7.4 sites therefore always take the PHP dumper, which is slower and restarts from
     * scratch on any interruption.
     *
     * TODO: remove this guard, and the version floor in composer.json, once the minimum
     *       supported PHP is 8.0.
     *
     * @return bool
     */
    private static function supportsShellDumper(): bool
    {
        return PHP_VERSION_ID >= 80000;
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
