<?php

namespace Modular\Connector\Manifest;

use Modular\Connector\Manifest\Contracts\ManifestablePartContract;
use function Modular\ConnectorDependencies\data_get;

/**
 * One disk slice of a manifest-driven flow (a backup part, a scan part).
 *
 * Holds everything `AbstractManifest` and `CalculateManifestJob` need to walk
 * the disk and write its manifest — type, cursor, limits, the mapped
 * exclusions — and the payload parsing both flows share. Each flow keeps its
 * own status vocabulary, events and post-manifest pipeline behind the
 * abstract hooks.
 */
abstract class AbstractManifestPart implements ManifestablePartContract
{
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
     * File disks that `core` expands to on a default filesystem.
     */
    public const CORE_SIBLING_DISKS = [
        self::INCLUDE_PLUGINS,
        self::INCLUDE_THEMES,
        self::INCLUDE_MU_PLUGINS,
        self::INCLUDE_CONTENT,
        self::INCLUDE_UPLOADS,
    ];

    /**
     * @var string
     */
    public string $mrid;

    /**
     * @var string
     */
    public string $name = '';

    /**
     * @var string One of the INCLUDE_* disk types.
     */
    public string $type = '';

    /**
     * @var string Flow-specific status; starts at `getManifestPendingStatus()`.
     */
    public string $status = '';

    /**
     * @var int Enumeration cursor: items the finder has already yielded.
     */
    public int $offset = 0;

    /**
     * @var int Rows in the finished manifest.
     */
    public int $totalItems = 0;

    /**
     * @var int Items enumerated per CalculateManifestJob run.
     */
    public int $limit = 5000;

    /**
     * @var string Manifest path on `getManifestDisk()`.
     */
    public string $manifestPath = '';

    /**
     * @var string FILESYSTEM_DEFAULT (standard layout) | FILESYSTEM_CUSTOM (explicit disks).
     */
    public string $filesystem = self::FILESYSTEM_DEFAULT;

    /**
     * @var string[] Disk types the payload includes.
     */
    public array $included = [];

    /**
     * @var string[] Disk-relative exclusions; `''` means the whole disk.
     */
    public array $excludedFiles = [];

    /**
     * @var string[]
     */
    public array $excludedExtensions = [];

    /**
     * @var string[] Physical table names (database part only).
     */
    public array $excludedTables = [];

    /**
     * @var int Files above this size (bytes) are skipped; 0 = no limit.
     */
    public int $excludedSize = 0;

    /**
     * @var string Checksum algorithm for the manifest rows. Backups negotiate it
     *             with the API (`batch.hash_algo`); scans need SHA256.
     */
    public string $hashAlgorithm = 'sha256';

    /**
     * @param string $mrid
     */
    public function __construct(string $mrid)
    {
        $this->mrid = $mrid;
        $this->status = $this->getManifestPendingStatus();
    }

    /**
     * @param string $type
     * @return $this
     */
    public function setType(string $type)
    {
        $this->type = $type;

        return $this;
    }

    /**
     * @return string
     */
    abstract public function getManifestPendingStatus(): string;

    /**
     * @return string
     */
    abstract public function getManifestInProgressStatus(): string;

    /**
     * Status the part moves to once the manifest is complete and non-empty.
     *
     * @return string
     */
    abstract public function getManifestDoneStatus(): string;

    /**
     * @return string
     */
    abstract public function getManifestExcludedStatus(): string;

    /**
     * Storage disk that holds the manifest file.
     *
     * @return string
     */
    abstract public function getManifestDisk(): string;

    /**
     * Middle segment of the manifest filename (`{name}-{infix}-{type}`).
     *
     * @return string
     */
    abstract protected function manifestPathInfix(): string;

    /**
     * Map the payload's `excluded` object onto this disk. Each flow has its own
     * payload keys; `ExcludedPaths::forDisk()` does the actual projection.
     *
     * @param string $type
     * @param mixed $excluded
     * @return string[]
     */
    abstract protected function resolveExcludedFiles(string $type, $excluded): array;

    /**
     * @param string $status
     * @param bool $emitEvent
     * @param array $extra
     * @return $this
     */
    abstract public function markAs($status, bool $emitEvent = true, array $extra = []);

    /**
     * Remove the part's artifacts. `$manifest` asks for the manifest file too.
     *
     * @param bool $manifest
     * @return void
     */
    abstract public function cleanFiles(bool $manifest = false): void;

    /**
     * @param array $tables
     * @return string[]
     */
    protected function resolveExcludedTables(array $tables): array
    {
        return array_values($tables);
    }

    /**
     * @param \stdClass $payload
     * @return int
     */
    protected function resolveExcludedSize(\stdClass $payload): int
    {
        return (int)(data_get($payload, 'excluded.max_file_size') ?: 0);
    }

    /**
     * Parse the part of the payload both flows share. Call it from
     * `setPayload()` after `setType()` — the exclusions depend on the type.
     *
     * @param \stdClass $payload
     * @return void
     */
    protected function applyCommonPayload(\stdClass $payload): void
    {
        $this->name = trim((string)$payload->name, '"');
        $this->filesystem = isset($payload->filesystem) ? (string)$payload->filesystem : self::FILESYSTEM_DEFAULT;

        $included = (array)($payload->included ?? []);

        // Default filesystem: the API only names `core` and/or `database`; the
        // plugin owns the list of WordPress disks `core` stands for. Custom
        // filesystem: the API names the disks explicitly and they are honoured.
        if ($this->filesystem === self::FILESYSTEM_DEFAULT && in_array(self::INCLUDE_CORE, $included, true)) {
            $included = array_values(array_unique(array_merge($included, self::CORE_SIBLING_DISKS)));
        }

        $this->included = $included;

        if (isset($payload->batch->max_files)) {
            $this->limit = (int)$payload->batch->max_files;
        }

        $excluded = data_get($payload, 'excluded', []);

        if ($this->type === self::INCLUDE_DATABASE) {
            $this->excludedTables = $this->resolveExcludedTables((array)(data_get($excluded, 'database') ?: []));

            return;
        }

        $this->excludedFiles = $this->resolveExcludedFiles($this->type, $excluded);
        $this->excludedExtensions = (array)(data_get($excluded, 'extensions') ?: []);
        $this->excludedSize = $this->resolveExcludedSize($payload);
    }

    /**
     * @return $this
     */
    public function setManifestPath()
    {
        $this->manifestPath = sprintf('%s-%s-%s', $this->name, $this->manifestPathInfix(), $this->type);

        return $this;
    }

    /**
     * Excluded when the payload did not include this disk, or when a mapped
     * exclusion covers the whole disk (`''`, see `ExcludedPaths`).
     *
     * @return $this
     */
    public function calculateExclusion()
    {
        $status = $this->getManifestPendingStatus();

        if (!in_array($this->type, $this->included, true) || in_array('', $this->excludedFiles, true)) {
            $status = $this->getManifestExcludedStatus();
        }

        return $this->markAs($status, false);
    }

    /**
     * @param string $status
     * @param \Throwable|null $e
     * @return void
     */
    public function markAsFailed($status, ?\Throwable $e = null)
    {
        $this->cleanFiles(true);

        $extra = [];

        if ($e !== null) {
            $extra = [
                'error' => [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ],
            ];
        }

        $this->markAs($status, true, $extra);
    }
}
