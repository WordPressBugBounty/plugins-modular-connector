<?php

namespace Modular\Connector\Backups\Iron;

use Modular\Connector\Backups\Iron\Events\ManagerBackupPartUpdated;
use Modular\Connector\Backups\Iron\Helpers\File;
use Modular\Connector\Backups\Iron\Jobs\DatabaseDumpJob;
use Modular\Connector\Backups\Iron\Jobs\ProcessFilesJob;
use Modular\Connector\Backups\Iron\Jobs\ProcessUploadJob;
use Modular\Connector\Backups\Iron\Manifest\Manifest;
use Modular\Connector\Facades\Manager;
use Modular\Connector\Manifest\AbstractManifestPart;
use Modular\Connector\Manifest\Contracts\ManifestContract;
use Modular\Connector\Manifest\ExcludedPaths;
use Modular\ConnectorDependencies\Carbon\Carbon;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Cache;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Config;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Storage;
use function Modular\ConnectorDependencies\data_get;
use function Modular\ConnectorDependencies\dispatch;
use function Modular\ConnectorDependencies\event;

class BackupPart extends AbstractManifestPart
{
    /**
     * @var int
     */
    public int $siteBackup;

    /**
     * @var int
     */
    public int $batch = 1;

    /**
     * @var int
     */
    public int $batchSize = 0;

    /**
     * @var bool
     */
    public bool $onlyWithTheSamePrefix = false;

    /**
     * @var int
     */
    public int $batchMaxFileSize = 128 * 1024 * 1024; // 128 MB

    /**
     * @var int
     */
    public int $batchMaxTimeout = 1800; // 30 minutes

    /**
     * Resolved lazily from Config on access.
     *
     * @var array
     */
    public array $connection = [];

    /**
     * @var int
     */
    public int $timestamp = 0;

    /**
     * @var int
     */
    public int $lastWebhookSent = 0;

    /**
     * Hash algorithm to use for file checksums.
     * Resolved from 'auto' to concrete algorithm (xxh128|md5|sha256) in setPayload()
     *
     * @var string
     */
    public string $hashAlgorithm = 'auto';

    /**
     * Dump binary resolved once during the plan phase: 'mariadb-dump', 'mysqldump' or null
     * when the dump must go through PHP (no shell, no binary, or PHP < 8.0).
     *
     * @var string|null
     */
    public ?string $dumpBinary = null;

    /**
     * @var string
     */
    public string $dumpPhase = self::DUMP_PHASE_PLAN;

    /**
     * Large tables planned for individual segments, in dump order. Each entry:
     * ['name' => string, 'size' => int, 'rows' => int, 'avgRow' => int]
     *
     * @var array
     */
    public array $dumpTables = [];

    /**
     * Index into $dumpTables of the table currently being dumped.
     *
     * @var int
     */
    public int $dumpTableIndex = 0;

    /**
     * Resume state of the table currently being dumped:
     * ['chunked' => bool, 'pkColumn' => string|null, 'pk' => int, 'row' => int, 'bytes' => int]
     *
     * @var array
     */
    public array $dumpCursor = [];

    /**
     * Tables at or above this size (data + index bytes) get their own segment.
     *
     * @var int
     */
    public int $dumpLargeTableSize = 100 * 1024 * 1024; // 100 MB

    /**
     * True once the one-shot dump of the small tables lost the connection. It survives
     * redispatch so a resumed tick goes straight to the chunked path instead of retrying
     * a one-shot that already proved it cannot finish here.
     *
     * @var bool
     */
    public bool $dumpSmallChunked = false;

    /**
     * Small tables to dump one by one, resolved lazily the first time the fallback runs so
     * the happy-path job payload never carries them. Each entry: ['name' => …, 'avgRow' => …]
     *
     * @var array
     */
    public array $dumpSmallTables = [];

    /**
     * Index into $dumpSmallTables of the table currently being dumped.
     *
     * @var int
     */
    public int $dumpSmallTableIndex = 0;

    public const DUMP_PHASE_PLAN = 'plan';
    public const DUMP_PHASE_SMALL = 'small';
    public const DUMP_PHASE_LARGE = 'large';
    public const DUMP_PHASE_MERGE = 'merge';
    public const DUMP_PHASE_COMPRESS = 'compress';

    /**
     * Resolve database connection from Config.
     *
     * @return array
     */
    private static function resolveConnection(): array
    {
        return [
            'host' => Config::get('database.connections.wordpress.host'),
            'port' => Config::get('database.connections.wordpress.port'),
            'database' => Config::get('database.connections.wordpress.database'),
            'username' => Config::get('database.connections.wordpress.username'),
            'password' => Config::get('database.connections.wordpress.password'),
            'socket' => Config::get('database.connections.wordpress.unix_socket'),
        ];
    }

    /**
     * Exclude connection credentials from serialized payload to prevent leaks in queue tables and logs.
     *
     * @return array
     */
    public function __serialize(): array
    {
        $data = get_object_vars($this);
        unset($data['connection']);

        return $data;
    }

    /**
     * Restore object state and resolve connection from Config.
     *
     * @param array $data
     * @return void
     */
    public function __unserialize(array $data): void
    {
        foreach ($data as $key => $value) {
            $this->$key = $value;
        }

        $this->connection = static::resolveConnection();
    }

    /**
     * @param \stdClass $payload
     * @return $this
     */
    public function setPayload(\stdClass $payload): BackupPart
    {
        $this->siteBackup = (int)$payload->site_backup;

        // Read before the shared parsing: resolveExcludedTables() depends on it.
        $this->onlyWithTheSamePrefix = data_get($payload, 'batch.database_same_prefix', false);

        // name, filesystem, included (core expanded to every disk), batch.max_files
        // and the excluded.* lists — shared with the scan driver.
        $this->applyCommonPayload($payload);

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

        $largeTableSize = (int)data_get($payload, 'batch.database_large_table_size', 0);

        if ($largeTableSize >= 1024 * 1024) {
            $this->dumpLargeTableSize = $largeTableSize;
        }

        $this->connection = static::resolveConnection();

        $this->timestamp = data_get($payload, 'timestamp', 0);

        $algorithm = data_get($payload, 'batch.hash_algo', 'auto');
        $this->hashAlgorithm = $this->getHashAlgorithm($algorithm);

        return $this;
    }

    /**
     * Select the best available hash algorithm with validation and fallback.
     *
     * If requested algorithm is unavailable, falls back automatically.
     *
     * @param string $preferred Preferred algorithm ('auto' or specific like 'sha256')
     * @return string
     */
    private function getHashAlgorithm(string $preferred): string
    {
        $available = hash_algos();

        // If a valid specific algorithm was requested, use it
        if ($preferred !== 'auto' && in_array($preferred, $available, true)) {
            return $preferred;
        }

        // Log fallback if specific algorithm was requested but not available
        if ($preferred !== 'auto') {
            Log::warning('BackupPart: Requested hash algorithm not available, using fallback', [
                'requested' => $preferred,
                'available_count' => count($available),
            ]);
        }

        // Fallback priority: fastest to slowest
        $fallback = ['xxh128', 'md5', 'sha256'];

        foreach ($fallback as $algo) {
            if (in_array($algo, $available, true)) {
                return $algo;
            }
        }

        throw new \RuntimeException(sprintf(
            'No suitable hash algorithm available. Required one of: %s. Available: %s',
            implode(', ', $fallback),
            implode(', ', $available)
        ));
    }

    /**
     * @param string $type
     * @param mixed $excluded
     * @return array
     */
    protected function resolveExcludedFiles(string $type, $excluded): array
    {
        // Default filesystem: `excluded.files` holds root-relative paths that apply
        // to every disk. Custom filesystem: `excluded.{type}` holds this disk's own
        // list. Same mapper as the scan driver — whole-segment matching, separators
        // normalised, `''` when the whole disk is excluded.
        if ($this->filesystem === self::FILESYSTEM_DEFAULT) {
            return ExcludedPaths::forDisk($type, (array)(data_get($excluded, 'files') ?: []));
        }

        return ExcludedPaths::forDisk($type, [], (array)(data_get($excluded, $type) ?: []));
    }

    /**
     * Gets the excluded tables.
     *
     * @param array $excludedTables
     * @return array
     */
    protected function resolveExcludedTables(array $excludedTables): array
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
     * @return string
     */
    public function getManifestPendingStatus(): string
    {
        return ManagerBackupPartUpdated::STATUS_PENDING;
    }

    /**
     * @return string
     */
    public function getManifestInProgressStatus(): string
    {
        return ManagerBackupPartUpdated::STATUS_MANIFEST_IN_PROGRESS;
    }

    /**
     * A finished backup manifest goes straight to the upload queue.
     *
     * @return string
     */
    public function getManifestDoneStatus(): string
    {
        return ManagerBackupPartUpdated::STATUS_MANIFEST_UPLOAD_PENDING;
    }

    /**
     * @return string
     */
    public function getManifestExcludedStatus(): string
    {
        return ManagerBackupPartUpdated::STATUS_EXCLUDED;
    }

    /**
     * @return string
     */
    public function getManifestDisk(): string
    {
        return 'backups';
    }

    /**
     * @return string
     */
    protected function manifestPathInfix(): string
    {
        return 'manifest';
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

        if ($this->type === self::INCLUDE_DATABASE) {
            for ($index = 0; $index <= count($this->dumpTables); $index++) {
                $segment = $this->getSegmentFileName($index);

                if (Storage::disk('backups')->exists($segment)) {
                    Storage::disk('backups')->delete($segment);
                }
            }

            for ($index = 0; $index < count($this->dumpSmallTables); $index++) {
                $scratch = $this->getSmallScratchFileName($index);

                if (Storage::disk('backups')->exists($scratch)) {
                    Storage::disk('backups')->delete($scratch);
                }
            }
        }

        Storage::disk('backups')->delete($this->getFileNameWithExtension('zip'));
    }

    /**
     * Dump segment file name: index 0 holds every small table, index N (1-based) holds
     * `$dumpTables[N - 1]`. Indexes instead of table names keep the name filesystem-safe
     * and deterministic, so segments can be cleaned or resumed without extra state.
     *
     * @param int $index
     * @return string
     */
    public function getSegmentFileName(int $index): string
    {
        return sprintf('%s-%s-segment-%03d.sql', $this->name, $this->type, $index);
    }

    /**
     * Scratch file holding one small table while the segment-0 fallback dumps it in chunks.
     * Its content is appended into segment 0 and the file deleted right after, so at most
     * one of these exists at a time.
     *
     * @param int $index
     * @return string
     */
    public function getSmallScratchFileName(int $index): string
    {
        return sprintf('%s-%s-small-%03d.sql', $this->name, $this->type, $index);
    }

    /**
     * The resolved dump plan, or null while it does not exist yet.
     *
     * Attached to every webhook of the database part (not only manifest_done): the API
     * processes the newest status and discards anything older, so a keepalive overtaking
     * manifest_done must not lose the plan with it.
     *
     * @return array|null
     */
    public function getDumpPlan(): ?array
    {
        if ($this->type !== self::INCLUDE_DATABASE || $this->dumpPhase === self::DUMP_PHASE_PLAN) {
            return null;
        }

        return [
            'binary' => $this->dumpBinary,
            'threshold' => $this->dumpLargeTableSize,
            'segments' => $this->totalItems,
            'large_tables' => array_map(
                fn($table) => [
                    'name' => $table['name'],
                    'size' => $table['size'],
                    'rows' => $table['rows'],
                ],
                $this->dumpTables
            ),
        ];
    }

    /**
     * @return ManifestContract
     */
    public function createManifest()
    {
        return Manifest::create($this);
    }

    /**
     * @return string
     */
    public function getManifestFailedStatus()
    {
        return ManagerBackupPartUpdated::STATUS_FAILED_EXPORT_MANIFEST;
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
            if (!empty($extra['force_redispatch'])) {
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

                    if ($this->type === self::INCLUDE_DATABASE) {
                        dispatch(new DatabaseDumpJob($this));
                    } else {
                        dispatch(new ProcessFilesJob($this));
                    }
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

            if ($this->type === self::INCLUDE_DATABASE) {
                dispatch(new DatabaseDumpJob($this));
            } else {
                dispatch(new ProcessFilesJob($this));
            }
        } elseif ($status === ManagerBackupPartUpdated::STATUS_DONE) {
            $this->cleanFiles($this->isDone());
        }

        return $this;
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
