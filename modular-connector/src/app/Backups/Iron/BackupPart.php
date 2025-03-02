<?php

namespace Modular\Connector\Backups\Iron;

use Modular\Connector\Backups\Iron\Events\ManagerBackupPartUpdated;
use Modular\Connector\Backups\Iron\Helpers\File;
use Modular\Connector\Backups\Iron\Jobs\ProcessFilesJob;
use Modular\Connector\Backups\Iron\Jobs\ProcessUploadJob;
use Modular\Connector\Backups\Iron\Manifest\Manifest;
use Modular\Connector\Facades\Manager;
use Modular\ConnectorDependencies\Illuminate\Support\Collection;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Cache;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Storage;
use Modular\ConnectorDependencies\Illuminate\Support\LazyCollection;
use Modular\ConnectorDependencies\Illuminate\Support\Str;
use function Modular\ConnectorDependencies\data_get;
use function Modular\ConnectorDependencies\dispatch;
use function Modular\ConnectorDependencies\event;

/**
 * Optimized BackupOptions class.
 */
class BackupPart
{
    /**
     * @var string
     */
    public string $mrid;

    /**
     * @var string
     */
    public string $name;

    /**
     * @var int
     */
    public int $siteBackup;

    /**
     * @var int
     */
    public int $totalItems = 0;

    /**
     * @var string
     */
    public string $type;

    /**
     * @var int
     */
    public int $offset = 0;

    /**
     * @var string
     */
    public string $status = ManagerBackupPartUpdated::STATUS_PENDING;

    /**
     * @var int
     */
    public int $batch = 1;

    /**
     * @var int
     */
    public int $batchSize = 0;

    /**
     * @var string
     */
    public string $manifestPath;

    /**
     * @var string
     */
    public string $filesystem = self::FILESYSTEM_DEFAULT;

    /**
     * @var array|string[]
     */
    public array $included = [];

    /**
     * @var array|string[]
     */
    public array $excludedFiles = [];

    /**
     * @var array|string[]
     */
    public array $excludedTables = [];

    /**
     * @var int
     */
    public int $limit = 5000;

    /**
     * @var int
     */
    public int $batchMaxFileSize = 128 * 1024 * 1024; // 128 MB

    /**
     * @var int
     */
    public int $batchMaxTimeout = 1800; // 30 minutes

    /**
     * @var array
     */
    public array $connection;

    /**
     * @var int
     */
    public int $timestamp = 0;

    public const INCLUDE_DATABASE = 'database';
    public const INCLUDE_CORE = 'core';
    public const INCLUDE_PLUGINS = 'plugins';
    public const INCLUDE_THEMES = 'themes';
    public const INCLUDE_MU_PLUGINS = 'mu_plugins';
    public const INCLUDE_CONTENT = 'content';
    public const INCLUDE_UPLOADS = 'uploads';

    public const FILESYSTEM_DEFAULT = 'default';
    public const FILESYSTEM_CUSTOM = 'custom';

    /**
     * Class constructor.
     *
     * @param string $mrid
     * @param \stdClass $payload
     */
    public function __construct(string $mrid)
    {
        $this->mrid = $mrid;
    }

    /**
     * @param \stdClass $payload
     * @return $this
     */
    public function setPayload(\stdClass $payload): BackupPart
    {
        $this->name = trim($payload->name, '"');
        $this->siteBackup = (int)$payload->site_backup;

        $this->filesystem = $filesystem = $payload->filesystem ?? self::FILESYSTEM_DEFAULT;
        $included = $payload->included ?? [];

        // When the filesystem is default and the core is included, we need to include all other directories
        if ($filesystem === self::FILESYSTEM_DEFAULT && in_array(self::INCLUDE_CORE, $included)) {
            $included = array_merge($included, [
                self::INCLUDE_PLUGINS,
                self::INCLUDE_THEMES,
                self::INCLUDE_MU_PLUGINS,
                self::INCLUDE_CONTENT,
                self::INCLUDE_UPLOADS,
            ]);
        }

        $this->included = $included;

        $this->excludedFiles = Collection::make($included)
            ->filter(fn($type) => $type !== self::INCLUDE_DATABASE)
            ->mapWithKeys(fn($type) => [$type => $this->getExcludedFiles($type, data_get($payload, 'excluded', []))])
            ->toArray();

        $this->excludedTables = $this->getExcludedTables(data_get($payload, 'excluded.tables', []));

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

        $this->timestamp = data_get($payload, 'timestamp', 0);

        return $this;
    }

    /**
     * Parses the DB_HOST setting to interpret it for mysqli_real_connect().
     *
     * @param string $host
     * @return array
     */
    private function parseDbHost(string $host): array
    {
        global $wpdb;

        return $wpdb->parse_db_host($host);
    }

    /**
     * Gets the excluded files.
     *
     * @param string $type
     * @param mixed $excluded
     * @return array
     */
    private function getExcludedFiles(string $type, $excluded): array
    {
        $filesystem = $this->filesystem;

        if ($filesystem === self::FILESYSTEM_DEFAULT) {
            $files = data_get($excluded, 'files', []);

            $relativePath = File::getRelativeDiskToDisk($type, 'core');

            $files = Collection::make($files)
                ->map(fn($file) => ltrim(Str::after($file, $relativePath), '/\\'))
                ->toArray();
        } else {
            $files = data_get($excluded, $type, []);
        }

        $defaultExclusions = File::getDefaultExclusions($type);
        $excluded = array_merge($defaultExclusions, $files);

        return array_values(array_unique($excluded));
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
            ->filter(fn($table) => in_array($table->path, $excludedTables) || in_array($table->name, $excludedTables))
            ->values()
            ->map(fn($table) => $table->name)
            ->toArray();
    }

    /**
     * @param string $type
     * @return $this
     */
    public function setType(string $type): BackupPart
    {
        $this->type = $type;
        $this->excludedFiles = data_get($this->excludedFiles, $type, []);

        return $this;
    }

    /**
     * @return $this
     */
    public function setManifestPath(): BackupPart
    {
        $this->manifestPath = Manifest::path($this);

        return $this;
    }

    /**
     * @return $this
     */
    public function calculateExclusion(): BackupPart
    {
        $status = ManagerBackupPartUpdated::STATUS_PENDING;

        $isIncluded = array_search($this->type, $this->included);

        if ($isIncluded === false) {
            $status = ManagerBackupPartUpdated::STATUS_EXCLUDED;
        } else {
            $isExcluded = in_array('', data_get($this->excludedFiles, $this->type, []));

            if ($isExcluded) {
                $status = ManagerBackupPartUpdated::STATUS_EXCLUDED;
            }
        }

        return $this->markAs($status, false);
    }

    /**
     * @param bool $manifest
     * @return void
     */
    public function cleanFiles(bool $manifest = false): void
    {
        if ($manifest && $this->type !== self::INCLUDE_DATABASE && Storage::disk('backups')->exists($this->manifestPath)) {
            Storage::disk('backups')->delete($this->manifestPath);
        } elseif (Storage::disk('backups')->exists($this->getFileNameWithExtension('sql'))) {
            Storage::disk('backups')->delete($this->getFileNameWithExtension('sql'));
        }

        Storage::disk('backups')->delete($this->getFileNameWithExtension('zip'));
    }

    /**
     * @param $status
     * @param bool $emitEvent
     * @param array $extra
     * @return BackupPart
     */
    public function markAs($status, bool $emitEvent = true, array $extra = []): BackupPart
    {
        if ($this->status === $status) {
            return $this;
        }

        $this->status = $status;

        // The excluded and pending statuses are not needed to be updated
        if ($emitEvent) {
            event(new ManagerBackupPartUpdated($this, $status, $extra));
        }

        if ($status === ManagerBackupPartUpdated::STATUS_EXCLUDED) {
            $this->cleanFiles(true);
        } elseif ($status === ManagerBackupPartUpdated::STATUS_MANIFEST_UPLOAD_PENDING) {
            dispatch(new ProcessUploadJob($this, true));
        } elseif ($status === ManagerBackupPartUpdated::STATUS_UPLOAD_PENDING) {
            dispatch(new ProcessUploadJob($this));
        } elseif ($status === ManagerBackupPartUpdated::STATUS_MANIFEST_DONE) {
            // We set the offset to 0 because the file reader starts from 0
            $this->offset = 0;

            dispatch(new ProcessFilesJob($this));
        } elseif ($status === ManagerBackupPartUpdated::STATUS_DONE) {
            $this->cleanFiles($this->isDone());
        }

        return $this;
    }

    /**
     * @param string $status
     * @param \Throwable|null $e
     */
    public function markAsFailed($status, ?\Throwable $e = null)
    {
        $this->cleanFiles(true);

        $this->markAs(
            $status,
            true,
            [
                'error' => [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ],
            ]
        );
    }

    /**
     * @return bool
     */
    public function isCancelled(): bool
    {
        return in_array($this->name, Cache::get('_cancelled_backup', []));
    }

    /**
     * @param int $start
     * @param int|null $end
     * @return array
     */
    public function nextFiles(int $start, int $end = null): array
    {
        $path = Storage::disk('backups')->path($this->manifestPath);
        $headers = [
            'checksum',
            'type',
            'size',
            'timestamp',
            'path',
        ];

        $lines = LazyCollection::make(function () use ($path) {
            $file = new \SplFileObject($path);
            $file->setFlags(\SplFileObject::READ_CSV | \SplFileObject::SKIP_EMPTY);
            $file->setCsvControl(File::$delimiter, File::$enclosure, File::$escape);

            while (!$file->eof()) {
                yield $file->fgetcsv();
            }
        })->skip($start);

        // If no end is provided, return the first row
        if ($end === null) {
            $row = $lines->first();

            return ($row && count($row) > 1) ? array_combine($headers, $row) : [];
        }

        return $lines->take($end - $start + 1)
            ->filter(fn($row) => is_array($row) && count($row) > 1)
            ->map(fn($row) => array_combine($headers, $row))
            ->values()
            ->all();
    }

    /**
     * @return bool
     */
    public function isDone(): bool
    {
        return $this->offset >= $this->totalItems && ($this->type === self::INCLUDE_DATABASE || empty($this->nextFiles($this->offset + 1)));
    }

    /**
     * @param bool $isManifest
     * @return string
     */
    public function getFileName(bool $isManifest = false): string
    {
        $batch = $isManifest ? 'manifest' : sprintf('part-%s', $this->batch);

        return sprintf('%s-%s-%s', $this->name, $this->type, $batch);
    }

    /**
     * @param string $extension
     * @return string
     */
    public function getFileNameWithExtension(string $extension): string
    {
        return sprintf('%s.%s', $this->getFileName(), $extension);
    }

    /**
     * @param string $extension
     * @return mixed
     */
    public function getPath(string $extension)
    {
        return Storage::disk('backups')->path($this->getFileNameWithExtension($extension));
    }

    /**
     * @param string $extension
     * @return int
     */
    public function getPathSize(string $extension): int
    {
        return Storage::disk('backups')->size($this->getFileNameWithExtension($extension));
    }
}
