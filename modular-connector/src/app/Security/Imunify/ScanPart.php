<?php

namespace Modular\Connector\Security\Imunify;

use Modular\Connector\Manifest\AbstractManifestPart;
use Modular\Connector\Manifest\Contracts\ManifestContract;
use Modular\Connector\Manifest\ExcludedPaths;
use Modular\Connector\Security\Facades\Scan;
use Modular\Connector\Security\Imunify\Events\ManagerScanPartUpdated;
use Modular\Connector\Security\Imunify\Manifest\ScanManifest;
use Modular\ConnectorDependencies\Carbon\Carbon;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Cache;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Storage;
use function Modular\ConnectorDependencies\data_get;
use function Modular\ConnectorDependencies\event;

/**
 * One disk of a malware scan. The shared part of the lifecycle (payload
 * parsing, exclusion mapping, manifest path, exclusion status) comes from
 * `AbstractManifestPart`; what is specific here is the allowlist of scannable
 * extensions, the scan status vocabulary, the per-disk webhook and the fan-in
 * coordination cache the merge listener reads.
 */
class ScanPart extends AbstractManifestPart
{
    /**
     * Directories no scan looks into, on every disk: VCS metadata and build
     * trees the runtime never loads. Applied through the same per-file
     * exclusion check as the user's own entries.
     */
    private const DEFAULT_DIRECTORY_EXCLUSIONS = ['.git', 'node_modules', '.opcache'];

    /**
     * @var int Default cap for files entering the scan manifest (5 MB); the
     *          API can override it with `max_file_size`.
     */
    private const DEFAULT_MAX_FILE_SIZE = 5242880;

    /**
     * @var int
     */
    public int $siteScan = 0;

    /**
     * @var array Default allowlist, aligned with Imunify's own plugin: every
     *            suffix a hosting stack may execute as PHP (php3/4/5/7, phps,
     *            runtime-included .inc) plus web-served types that can carry a
     *            script payload (.svg). Media and binary types stay out. The
     *            API can override it with `extensions`.
     */
    public array $allowedExtensions = [
        'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps', 'inc',
        'js', 'html', 'htm', 'htaccess', 'svg',
    ];

    /**
     * @var string Imunify matches verdicts by full SHA256; not negotiable.
     */
    public string $hashAlgorithm = 'sha256';

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

        $this->siteScan = (int)$payload->site_scan;

        if (isset($payload->extensions) && is_array($payload->extensions)) {
            $this->allowedExtensions = $payload->extensions;
        }

        // name, filesystem, included (core expanded to every disk), batch.max_files
        // and the excluded.* lists — shared with the backup driver.
        $this->applyCommonPayload($payload);

        // The scan name ends up in file names on the `scans` disk and in cache
        // keys, so it is reduced to a safe character set.
        $this->name = preg_replace('/[^a-zA-Z0-9._-]/', '', $this->name);

        return $this;
    }

    /**
     * Scan payloads carry the size cap at the top level, not under `excluded`.
     *
     * @param \stdClass $payload
     * @return int
     */
    protected function resolveExcludedSize(\stdClass $payload): int
    {
        return isset($payload->max_file_size) ? (int)$payload->max_file_size : self::DEFAULT_MAX_FILE_SIZE;
    }

    /**
     * Two sources are merged, so both payload shapes the API produces work:
     *
     *   - Root-relative paths under `excluded.core`, e.g.
     *     "wp-content/plugins/hello-dolly". They apply to every disk; an
     *     exclusion that is a PARENT of the disk's own path yields `''`, which
     *     `calculateExclusion()` reads as "the whole part is excluded".
     *   - Per-area paths under `excluded.{type}` (`plugins`, `themes`,
     *     `mu_plugins`, `content`, `uploads`), which the API only sends with
     *     `filesystem=custom` (the backup convention). Accepted either
     *     disk-relative ("hello-dolly") or root-relative
     *     ("wp-content/plugins/hello-dolly") until the API fixes the format.
     *     Their presence in `default` mode is an API bug worth surfacing, so
     *     it is logged.
     *
     * @param string $type
     * @param mixed $excluded
     * @return array
     */
    protected function resolveExcludedFiles(string $type, $excluded): array
    {
        $rootRelative = (array)(data_get($excluded, 'core') ?: []);

        $areaEntries = $type !== self::INCLUDE_CORE ? (array)(data_get($excluded, $type) ?: []) : [];

        if (!empty($areaEntries) && $this->filesystem === self::FILESYSTEM_DEFAULT) {
            Log::warning('ScanPart: per-area exclusions received with filesystem=default (API contract sends them only for custom)', [
                'site_scan' => $this->siteScan,
                'type' => $type,
                'count' => count($areaEntries),
            ]);
        }

        return ExcludedPaths::forDisk($type, $rootRelative, array_merge(self::DEFAULT_DIRECTORY_EXCLUSIONS, $areaEntries));
    }

    /**
     * @return string
     */
    public function getManifestPendingStatus(): string
    {
        return ManagerScanPartUpdated::STATUS_PENDING;
    }

    /**
     * @return string
     */
    public function getManifestInProgressStatus(): string
    {
        return ManagerScanPartUpdated::STATUS_IN_PROGRESS;
    }

    /**
     * @return string
     */
    public function getManifestDoneStatus(): string
    {
        return ManagerScanPartUpdated::STATUS_DONE;
    }

    /**
     * @return string
     */
    public function getManifestExcludedStatus(): string
    {
        return ManagerScanPartUpdated::STATUS_EXCLUDED;
    }

    /**
     * @return string
     */
    public function getManifestFailedStatus()
    {
        return ManagerScanPartUpdated::STATUS_FAILED;
    }

    /**
     * @return string
     */
    public function getManifestDisk(): string
    {
        return 'scans';
    }

    /**
     * @return string
     */
    protected function manifestPathInfix(): string
    {
        return 'scan-manifest';
    }

    /**
     * @return ManifestContract
     */
    public function createManifest()
    {
        return ScanManifest::create($this);
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
    public function markAs($status, bool $emitEvent = true, array $extra = [])
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
            $this->cleanFiles(true);
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
     * The per-disk manifest is the only artifact a scan part owns; `$manifest`
     * exists for signature parity with the backup part.
     *
     * @param bool $manifest
     * @return void
     */
    public function cleanFiles(bool $manifest = false): void
    {
        if ($this->manifestPath !== '' && Storage::disk('scans')->exists($this->manifestPath)) {
            Storage::disk('scans')->delete($this->manifestPath);
        }
    }
}
