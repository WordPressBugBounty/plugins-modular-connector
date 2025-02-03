<?php

namespace Modular\Connector\Backups\Phantom;

use Modular\Connector\Facades\Manager;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Cache;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Storage;
use Modular\ConnectorDependencies\Illuminate\Support\Str;

/**
 * Optimized BackupOptions class.
 */
class BackupOptions implements \JsonSerializable
{
    public string $mrid;
    public string $name;
    public int $siteBackup;

    public array $included = [];
    public array $excludedFiles = [];
    public array $excludedTables = [];

    public string $root;
    public int $limit = 5000;
    public int $batchMaxFileSize = 134217728; // 128 MB
    public int $batchMaxTimeout = 1800; // 30 minutes

    public array $connection;

    public const INCLUDE_DATABASE = 'database';
    public const INCLUDE_CORE = 'core';

    /**
     * Class constructor.
     *
     * @param string $mrid
     * @param \stdClass $payload
     */
    public function __construct(string $mrid, \stdClass $payload)
    {
        $this->mrid = $mrid;
        $this->name = trim($payload->name, '"');
        $this->siteBackup = (int)$payload->site_backup;

        $this->included = $payload->included ?? [];

        $this->excludedFiles = $this->getExcludedFiles($payload->excluded->files ?? []);
        $this->excludedTables = $this->getExcludedTables($payload->excluded->tables ?? []);

        if (isset($payload->batch->size)) {
            $size = $payload->batch->size;

            if ($size < 24 * 1024 * 1024) {
                $size = 24 * 1024 * 1024;
            } elseif ($size > 2 * 1024 * 1024 * 1024) {
                $size = 2 * 1024 * 1024 * 1024;
            }

            $this->batchMaxFileSize = $size;
        }

        if (isset($payload->batch->timeout)) {
            $this->batchMaxTimeout = $payload->batch->timeout;
        }

        if (isset($payload->batch->max_files)) {
            $this->limit = $payload->batch->max_files;
        }

        $this->connection = [
            'host' => $payload->db->host ?? DB_HOST,
            'database' => $payload->db->database ?? DB_NAME,
            'username' => $payload->db->username ?? DB_USER,
            'password' => $payload->db->password ?? DB_PASSWORD,
            'port' => null,
            'socket' => null,
        ];

        [$host, $port, $socket, $isIpv6] = $this->parseDbHost($this->connection['host']);

        if ($isIpv6 && extension_loaded('mysqlnd')) {
            $host = "[$host]";
        }

        $this->connection['host'] = $host;
        $this->connection['port'] = $port;
        $this->connection['socket'] = $socket;
    }

    /**
     * Parses the DB_HOST setting to interpret it for mysqli_real_connect().
     *
     * @param string $host
     * @return array
     */
    protected function parseDbHost(string $host): array
    {
        global $wpdb;

        return $wpdb->parse_db_host($host);
    }

    /**
     * Checks if the backup is finished.
     *
     * @return bool
     */
    public function finished(): bool
    {
        // Adjust logic based on defined properties
        return count($this->included) === 1 && $this->included[0] === self::INCLUDE_DATABASE;
    }

    /**
     * Gets the excluded files.
     *
     * @param array $files
     * @return array
     */
    private function getExcludedFiles(array $files = []): array
    {
        $defaultExclusions = [
            '.wp-cli',
            'error_log',
            Storage::disk('backups')->path(''),
            Storage::disk('content')->path('modular_storage'),
            Storage::disk('content')->path('upgrade-temp-backup'),
            Storage::disk('content')->path('litespeed'),
            Storage::disk('content')->path('error_log'),
            Storage::disk('content')->path('cache'),
            Storage::disk('content')->path('lscache'),
            Storage::disk('content')->path('et-cache'),
            Storage::disk('content')->path('updraft'),
            Storage::disk('content')->path('wpvividbackups'),
            Storage::disk('content')->path('aiowps_backups'),
            Storage::disk('content')->path('ai1wm-backups'),
            Storage::disk('content')->path('backups-dup-pro'),
            Storage::disk('content')->path('debug.log'),
            Storage::disk('content')->path('mysql.sql'),
        ];

        $excluded = array_merge($defaultExclusions, $files);

        $absPath = Storage::disk('core')->path('');

        $excluded = array_map(function ($item) use ($absPath) {
            if (Str::startsWith($item, $absPath)) {
                $item = str_replace($absPath, '', $item);
            }

            return untrailingslashit($item);
        }, $excluded);

        return array_values(array_unique($excluded));
    }

    /**
     * Adds excluded files.
     *
     * @param array $excludedFiles
     * @return void
     */
    public function addExcludedFiles(array $excludedFiles): void
    {
        $this->excludedFiles = $this->getExcludedFiles(array_merge($this->excludedFiles, $excludedFiles));
    }

    /**
     * Gets the excluded tables.
     *
     * @param array $excludedTables
     * @return array
     */
    private function getExcludedTables(array $excludedTables = []): array
    {
        $excludedTables = array_merge($excludedTables, Manager::driver('database')->views());

        return Manager::driver('database')->tree()
            ->filter(function ($table) use ($excludedTables) {
                return in_array($table->path, $excludedTables) || in_array($table->name, $excludedTables);
            })
            ->values()
            ->map(fn($table) => $table->name)
            ->toArray();
    }

    /**
     * Converts the object to an array.
     *
     * @return array
     */
    public function toArray(): array
    {
        return $this->jsonSerialize();
    }

    /**
     * Implementation of JsonSerializable.
     *
     * @return array
     */
    public function jsonSerialize(): array
    {
        $attributes = get_object_vars($this);

        unset($attributes['connection'], $attributes['mrid']);

        $snakeCaseAttributes = [];
        foreach ($attributes as $key => $value) {
            $snakeCaseAttributes[Str::snake($key)] = $value;
        }

        return $snakeCaseAttributes;
    }

    /**
     * @return bool
     */
    public function isCancelled(): bool
    {
        return in_array($this->name, Cache::get('_cancelled_backup', []));
    }
}
