<?php

namespace Modular\Connector\Backups\Iron;

use Modular\Connector\Backups\Iron\Events\ManagerBackupPartUpdated;
use Modular\Connector\Backups\Iron\Helpers\File;
use Modular\Connector\Backups\Iron\Jobs\ProcessFilesJob;
use Modular\Connector\Backups\Iron\Jobs\ProcessUploadJob;
use Modular\Connector\Backups\Iron\Manifest\Manifest;
use Modular\Connector\Facades\Manager;
use Modular\ConnectorDependencies\Carbon\Carbon;
use Modular\ConnectorDependencies\Illuminate\Support\Collection;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Cache;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Config;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Storage;
use Modular\ConnectorDependencies\Illuminate\Support\Str;
use function Modular\ConnectorDependencies\data_get;
use function Modular\ConnectorDependencies\dispatch;
use function Modular\ConnectorDependencies\event;

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
     * @var bool
     */
    public bool $onlyWithTheSamePrefix = false;

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

    /**
     * @var int
     */
    public int $lastWebhookSent = 0;

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
        $this->onlyWithTheSamePrefix = data_get($payload, 'batch.database_same_prefix', false);

        if ($this->type !== self::INCLUDE_DATABASE) {
            $this->excludedFiles = $this->getExcludedFiles($this->type, data_get($payload, 'excluded', []));
        } else {
            $this->excludedTables = $this->getExcludedTables(data_get($payload, 'excluded.database', []));
        }

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
            'host' => Config::get('database.connections.wordpress.host'),
            'port' => Config::get('database.connections.wordpress.port'),
            'database' => Config::get('database.connections.wordpress.database'),
            'username' => Config::get('database.connections.wordpress.username'),
            'password' => Config::get('database.connections.wordpress.password'),
            'socket' => Config::get('database.connections.wordpress.unix_socket'),
        ];

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
                // If the type is core, we need to exclude all files but if the type is another directory,
                // we need to exclude only the files from that directory
                ->filter(fn($file) => $type === 'core' || Str::startsWith($file, $relativePath))
                ->map(fn($file) => ltrim(Str::after($file, $relativePath), '/\\'))
                ->toArray();
        } else {
            // When we use a custom filesystem, we can get the excluded files directly
            $files = data_get($excluded, $type, []);
        }

        $defaultFileExclusions = File::getDefaultFileExclusions($type);
        $defaultDirectoryExclusions = File::getDefaultDirectoriesExclusions($type);
        $excluded = array_merge($defaultFileExclusions, $defaultDirectoryExclusions, $files);

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

        $prefix = Config::get('database.connections.wordpress.prefix');

        return Manager::driver('database')->tree()
            ->filter(
                fn($table) => $this->onlyWithTheSamePrefix && $table->prefix !== $prefix || in_array($table->path, $excludedTables) || in_array($table->name, $excludedTables)
            )
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
            $isExcluded = in_array('', $this->excludedFiles);

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
            if (!empty($extra['force_redispatch']) && $this->type !== self::INCLUDE_DATABASE) {
                if ($this->status === ManagerBackupPartUpdated::STATUS_IN_PROGRESS) {
                    Log::debug('Re-dispatch $part', [
                        'part' => $this->type,
                        'batch' => $this->batch,
                        'offset' => $this->offset,
                        'totalItems' => $this->totalItems,
                        'status' => $this->status,
                        'batchSize' => $this->batchSize,
                        'batchMaxFileSize' => $this->batchMaxFileSize,
                    ]);

                    dispatch(new ProcessFilesJob($this));
                }

                // In some hosting providers, the process of reading the manifest file is very slow,
                // so we need to send the event to avoid the API marking it as failed.
                if (
                    $emitEvent &&
                    $this->lastWebhookSent > 0 &&
                    Carbon::createFromTimestamp($this->lastWebhookSent)->lt(Carbon::now()->subMinutes(10))
                ) {
                    Log::debug('Force send webhook', [
                        'part' => $this->type,
                        'batch' => $this->batch,
                        'offset' => $this->offset,
                        'status' => $this->status,
                    ]);

                    $this->lastWebhookSent = Carbon::now()->timestamp;

                    event(new ManagerBackupPartUpdated($this, $status, $extra));
                }
            }

            return $this;
        }

        $this->status = $status;

        // The excluded and pending statuses are not needed to be updated
        if ($emitEvent) {
            $this->lastWebhookSent = Carbon::now()->timestamp;
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
     * @param int $limit
     * @return \Generator
     */
    public function nextFiles(int $start, int $limit = 1): \Generator
    {
        $path = Storage::disk('backups')->path($this->manifestPath);
        $headers = ['checksum', 'type', 'size', 'timestamp', 'path'];

        $file = new \SplFileObject($path);
        $file->setFlags(\SplFileObject::READ_CSV | \SplFileObject::SKIP_EMPTY | \SplFileObject::DROP_NEW_LINE);
        $file->setCsvControl(File::$delimiter, File::$enclosure, File::$escape);

        // Move pointer directly to $start
        $file->seek($start);

        $count = 0;

        while (!$file->eof() && $count < $limit) {
            $row = $file->current();

            // Only yield if it's a valid row with exactly the expected columns
            if (is_array($row) && count($row) === count($headers)) {
                yield array_combine($headers, $row);
                $count++;
            }

            $file->next();
        }
    }

    /**
     * @return bool
     */
    public function isDone(): bool
    {
        // If we haven't reached the total number of lines yet, we are not done
        if ($this->offset < $this->totalItems) {
            return false;
        }

        // If we only include the database, reaching the total is enough
        if ($this->type === self::INCLUDE_DATABASE) {
            return true;
        }

        // For other types, we check if there is at least ONE more line
        foreach ($this->nextFiles($this->offset + 1) as $notImportant) {
            // If we find at least one more line, we are not done
            return false;
        }

        // If we reach here, it means there are no more lines to process
        return true;
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
