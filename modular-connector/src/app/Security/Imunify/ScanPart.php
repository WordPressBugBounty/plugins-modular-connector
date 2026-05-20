<?php

namespace Modular\Connector\Security\Imunify;

use Modular\Connector\Backups\Iron\BackupPart;
use Modular\Connector\Backups\Iron\Helpers\File;
use Modular\Connector\Manifest\Contracts\ManifestablePartContract;
use Modular\Connector\Manifest\Contracts\ManifestContract;
use Modular\Connector\Security\Facades\Scan;
use Modular\Connector\Security\Imunify\Events\ManagerScanPartUpdated;
use Modular\Connector\Security\Imunify\Manifest\ScanManifest;
use Modular\ConnectorDependencies\Carbon\Carbon;
use Modular\ConnectorDependencies\Illuminate\Support\Collection;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Cache;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Storage;
use Modular\ConnectorDependencies\Illuminate\Support\Str;
use function Modular\ConnectorDependencies\data_get;
use function Modular\ConnectorDependencies\event;

class ScanPart implements ManifestablePartContract
{
    /**
     * @var string
     */
    public $mrid;

    /**
     * @var string
     */
    public $name;

    /**
     * @var int
     */
    public $siteScan;

    /**
     * @var string
     */
    public $type;

    /**
     * @var string
     */
    public $status = ManagerScanPartUpdated::STATUS_PENDING;

    /**
     * @var int
     */
    public $offset = 0;

    /**
     * @var int
     */
    public $totalItems = 0;

    /**
     * @var int
     */
    public $limit = 5000;

    /**
     * @var string
     */
    public $manifestPath;

    /**
     * @var int
     */
    public $maxFileSize = 5242880; // 5MB

    /**
     * @var array
     */
    public $allowedExtensions = ['php', 'phtml', 'js', 'html', 'htm', 'htaccess'];

    /**
     * @var string
     */
    public $hashAlgorithm = 'sha256';

    /**
     * @var array
     */
    public $excludedFiles = [];

    /**
     * @var array
     */
    public $excludedExtensions = [];

    /**
     * @var array
     */
    public $excludedTables = [];

    /**
     * @var array
     */
    public $included = [];

    /**
     * @var string 'default' (single-site) | 'custom' (e.g., per-disk explicit list)
     */
    public $filesystem = self::FILESYSTEM_DEFAULT;

    /**
     * @var bool When true, DbTablesDiscoverer must reject foreign-tenant prefixes (multisite).
     */
    public $onlyWithTheSamePrefix = false;

    public const INCLUDE_DATABASE = BackupPart::INCLUDE_DATABASE;
    public const INCLUDE_CORE = BackupPart::INCLUDE_CORE;
    public const INCLUDE_PLUGINS = BackupPart::INCLUDE_PLUGINS;
    public const INCLUDE_THEMES = BackupPart::INCLUDE_THEMES;
    public const INCLUDE_MU_PLUGINS = BackupPart::INCLUDE_MU_PLUGINS;
    public const INCLUDE_CONTENT = BackupPart::INCLUDE_CONTENT;
    public const INCLUDE_UPLOADS = BackupPart::INCLUDE_UPLOADS;

    public const FILESYSTEM_DEFAULT = BackupPart::FILESYSTEM_DEFAULT;
    public const FILESYSTEM_CUSTOM = BackupPart::FILESYSTEM_CUSTOM;

    /**
     * @param string $mrid
     */
    public function __construct($mrid)
    {
        $this->mrid = $mrid;
    }

    /**
     * @param string $type
     * @return $this
     */
    public function setType($type)
    {
        $this->type = $type;

        return $this;
    }

    /**
     * @param \stdClass $payload
     * @return $this
     */
    public function setPayload(\stdClass $payload)
    {
        $required = ['name', 'site_scan', 'included', 'filesystem'];

        foreach ($required as $field) {
            if (!isset($payload->$field)) {
                throw new \InvalidArgumentException(sprintf('Missing required payload field: %s', $field));
            }
        }

        $this->name = preg_replace('/[^a-zA-Z0-9._-]/', '', trim($payload->name, '"'));
        $this->siteScan = (int)$payload->site_scan;

        $this->filesystem = (string)$payload->filesystem;
        $included = $payload->included ?: [];

        // Contract: with filesystem=default the API only sends `core` and/or `database`.
        // We expand `core` to every WordPress file disk so the plugin owns the list.
        // For filesystem=custom the API sends specific file disks and we honor them as-is.
        if ($this->filesystem === self::FILESYSTEM_DEFAULT && in_array(self::INCLUDE_CORE, $included, true)) {
            $included = array_values(array_unique(array_merge($included, [
                self::INCLUDE_PLUGINS,
                self::INCLUDE_THEMES,
                self::INCLUDE_MU_PLUGINS,
                self::INCLUDE_CONTENT,
                self::INCLUDE_UPLOADS,
            ])));
        }

        $this->included = $included;
        $this->onlyWithTheSamePrefix = (bool)data_get($payload, 'batch.database_same_prefix', false);

        if (isset($payload->max_file_size)) {
            $this->maxFileSize = (int)$payload->max_file_size;
        }

        if (isset($payload->extensions) && is_array($payload->extensions)) {
            $this->allowedExtensions = $payload->extensions;
        }

        if (isset($payload->batch->max_files)) {
            $this->limit = (int)$payload->batch->max_files;
        }

        // File-disk parts use excluded.core (paths). Database parts use excluded.database (tables).
        if ($this->type === self::INCLUDE_DATABASE) {
            $this->excludedTables = data_get($payload, 'excluded.database') ?: [];
        } else {
            $this->excludedFiles = $this->getExcludedFiles($this->type, data_get($payload, 'excluded', []));
        }

        $this->excludedExtensions = data_get($payload, 'excluded.extensions') ?: [];

        return $this;
    }

    /**
     * Build the file-exclusion list for the current part type.
     *
     * Both 'default' and 'custom' filesystems use the same key: `excluded.core` with
     * paths relative to the WordPress root (e.g. "wp-content/plugins/hello-dolly/.*").
     * We map them to disk-relative paths and return:
     *   - `''` (empty string) when an exclusion is a PARENT of the disk's relative
     *      path → signals that the entire part is excluded (calculateExclusion picks
     *      this up to mark STATUS_EXCLUDED).
     *   - the disk-relative path otherwise.
     *
     * @param string $type
     * @param mixed $excluded
     * @return array
     */
    private function getExcludedFiles($type, $excluded)
    {
        $rawFiles = data_get($excluded, 'core', []);
        $relativePath = File::getRelativeDiskToDisk($type, 'core');

        $files = Collection::make($rawFiles)
            ->filter(function ($file) use ($type, $relativePath) {
                if ($type === self::INCLUDE_CORE) {
                    return true;
                }

                // Keep if the exclusion lives inside this disk
                if (Str::startsWith($file, $relativePath)) {
                    return true;
                }

                // Keep if the exclusion is a PARENT of this disk (e.g. "wp-content"
                // excluding the "themes" disk at "wp-content/themes")
                if (Str::startsWith($relativePath, $file)) {
                    return true;
                }

                return false;
            })
            ->map(function ($file) use ($relativePath) {
                if (Str::startsWith($relativePath, $file)) {
                    // Parent exclusion → mark the whole disk as excluded
                    return '';
                }

                $result = Str::after($file, $relativePath);

                return preg_replace('#^[/\\\\]+#', '', $result);
            })
            ->toArray();

        $defaultFileExclusions = File::getDefaultFileExclusions($type);
        $defaultDirectoryExclusions = File::getDefaultDirectoriesExclusions($type);
        $merged = array_merge($defaultFileExclusions, $defaultDirectoryExclusions, $files);

        return array_values(array_unique($merged));
    }

    /**
     * @return $this
     */
    public function setManifestPath()
    {
        $this->manifestPath = sprintf('%s-%s-%s', $this->name, 'scan-manifest', $this->type);

        return $this;
    }

    /**
     * @return $this
     */
    public function calculateExclusion()
    {
        $status = ManagerScanPartUpdated::STATUS_PENDING;

        if (!in_array($this->type, $this->included)) {
            $status = ManagerScanPartUpdated::STATUS_EXCLUDED;
        } elseif (in_array('', $this->excludedFiles, true)) {
            // A parent path in `excluded.core` covers this whole disk.
            $status = ManagerScanPartUpdated::STATUS_EXCLUDED;
        }

        return $this->markAs($status, false);
    }

    /**
     * @return ManifestContract
     */
    public function createManifest()
    {
        return ScanManifest::create($this);
    }

    /**
     * @return string
     */
    public function getManifestFailedStatus()
    {
        return ManagerScanPartUpdated::STATUS_FAILED;
    }

    /**
     * Mark the part with a new status, emit the per-disk event, publish the part
     * to the cross-part coordination cache when terminal, and (on EXCLUDED) remove
     * the leftover manifest file.
     *
     * Fan-in detection — deciding "all parts done → dispatch merge" — lives in
     * `ScanManifestCoordinatorListener`, which reacts to the event below. This
     * method only publishes "I am done"; the listener owns "should we advance?".
     *
     * @param string $status
     * @param bool $emitEvent
     * @param array $extra
     * @return $this
     */
    public function markAs($status, $emitEvent = true, array $extra = [])
    {
        if ($this->status === $status) {
            return $this;
        }

        $this->status = $status;

        Log::debug('ScanPart: Status changed', [
            'type' => $this->type,
            'status' => $status,
            'site_scan' => $this->siteScan,
        ]);

        if (ManagerScanPartUpdated::isManifestTerminal($status)) {
            $this->publishCoordinationState();
        }

        if ($emitEvent) {
            event(ManagerScanPartUpdated::forPart($this, $status, $extra));
        }

        if ($status === ManagerScanPartUpdated::STATUS_EXCLUDED) {
            $this->cleanFiles();
        }

        return $this;
    }

    /**
     * Record this part in the shared coordination cache `_scan_parts_status_<site_scan>`.
     * The fan-in listener reads this cache to decide when every expected per-disk
     * manifest has reported.
     *
     * Called from `markAs()` only when the new status is terminal — by construction
     * every entry in the cache is therefore a "this part is done" record.
     *
     * @return void
     */
    private function publishCoordinationState()
    {
        $cacheKey = sprintf('_scan_parts_status_%s', $this->siteScan);
        $partsStatus = Cache::get($cacheKey, []);

        $partsStatus[$this->type] = [
            'status' => $this->status,
            'manifest_path' => $this->manifestPath,
            'total_items' => $this->totalItems,
        ];

        Cache::put($cacheKey, $partsStatus, Carbon::now()->addDay());
    }

    /**
     * @param string $status
     * @param \Throwable|null $e
     * @return void
     */
    public function markAsFailed($status, \Throwable $e = null)
    {
        $this->cleanFiles();

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

    /**
     * @return bool
     */
    public function isCancelled()
    {
        if ($this->type === self::INCLUDE_DATABASE) {
            return Scan::isDatabaseCancelled($this->name);
        }

        return Scan::isFilesCancelled($this->name);
    }

    /**
     * @return void
     */
    public function cleanFiles()
    {
        if (Storage::disk('scans')->exists($this->manifestPath)) {
            Storage::disk('scans')->delete($this->manifestPath);
        }
    }
}
