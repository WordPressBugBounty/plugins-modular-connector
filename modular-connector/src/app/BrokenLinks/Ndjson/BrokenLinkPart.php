<?php

namespace Modular\Connector\BrokenLinks\Ndjson;

use Modular\Connector\BrokenLinks\Facades\BrokenLink;
use Modular\Connector\BrokenLinks\Ndjson\Events\ManagerBrokenLinkPartUpdated;
use Modular\Connector\BrokenLinks\Ndjson\Manifest\BrokenLinkManifest;
use Modular\Connector\BrokenLinks\Ndjson\Manifest\NdjsonWriter;
use Modular\Connector\Manifest\Contracts\ManifestablePartContract;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Storage;
use function Modular\ConnectorDependencies\data_get;
use function Modular\ConnectorDependencies\event;

/**
 * Carrier serialized between ProcessBrokenLinkBatchJob ticks. One instance per
 * public post-type — the post-type is BLC's analogue of a Backups "disk":
 * each part runs an independent batch loop and its own upload.
 *
 * Lifecycle (per part, per Backups-style):
 *   setType → setPayload → setManifestPath → calculateExclusion
 *     PENDING  → ProcessBrokenLinkBatchJob loop (emits IN_PROGRESS on first tick)
 *              → UploadBrokenLinkJob (emits UPLOADING then DONE)
 *     EXCLUDED → terminal up-front, no jobs dispatched
 *     FAILED   → terminal, emitted by Process/Upload failed()
 *
 * ManifestablePartContract is implemented for structural parity; BLC does not
 * use the shared CalculateManifestJob pipeline (NDJSON is written by the loop).
 */
class BrokenLinkPart implements ManifestablePartContract
{
    public const TYPE_FULL = 'full';
    public const TYPE_PAGE = 'page';

    /**
     * Render mode. PLAIN extracts from raw post_content (lightweight, misses
     * page-builder output and shortcodes). RENDER runs ContentRenderer +
     * apply_filters('the_content') (robust, heavier, has plugin side-effects).
     */
    public const MODE_PLAIN = 'plain';
    public const MODE_RENDER = 'render';

    /**
     * Resource types the current plugin version knows how to extract. The API
     * may send any of these (the request also lists `og_image` / `canonical`
     * etc.); types not in this list are silently dropped in
     * `validateResourceTypes()` so the API can introduce new types without
     * breaking older plugin versions. New types get added here when their
     * extractor lands in `LinkExtractor`.
     */
    public const SUPPORTED_RESOURCE_TYPES = ['link', 'image', 'script', 'stylesheet'];

    /**
     * Built-in deny list: post-types that never carry user-facing links to scan.
     * Marked EXCLUDED by calculateExclusion without consulting payload.
     */
    public const EXCLUDED_SOURCE_TYPES = [
        'attachment',
        'product_variation',
        'nav_menu_item',
        'wp_block',
        'wp_template',
        'wp_template_part',
        'wp_navigation',
        'wp_global_styles',
        'shop_order',
        'shop_coupon',
        'customize_changeset',
        'revision',
        'elementor_library',
        'e-landing-page',
        'e-floating-buttons',
    ];

    public string $mrid;
    public int $siteBrokenLinkScan = 0;
    public string $postType = '';
    public string $type = self::TYPE_FULL;
    public string $mode = self::MODE_PLAIN;
    public string $step = ManagerBrokenLinkPartUpdated::STEP_SCAN;
    public string $status = ManagerBrokenLinkPartUpdated::STATUS_PENDING;

    /** @var int[] Resolved post IDs for type=page. Empty for type=full (paginated). */
    public array $postIds = [];

    /**
     * Number of posts already processed across all ticks of this part. Used as the
     * cap counter for maxPages (all types) and as the slice offset into $postIds
     * for TYPE_PAGE (where the list is finite and resolved up-front).
     */
    public int $processedCount = 0;

    /**
     * Keyset cursor for TYPE_FULL only. Next batch queries WHERE ID > lastSeenId
     * ORDER BY ID ASC, so post edits between ticks never desync the offset and
     * inserts with higher IDs fall in naturally at the tail.
     */
    public int $lastSeenId = 0;

    public int $totalLinks = 0;
    public int $maxPages = 500;

    /** @var string[] */
    public array $resourceTypes = self::SUPPORTED_RESOURCE_TYPES;

    /** @var string[] PCRE patterns already wrapped with `~…~u`. */
    public array $excludedUrlPatterns = [];

    /** @var string[] User-provided post-type exclusions (excluded.source_types). */
    public array $excludedSourceTypes = [];

    /**
     * `check.internal` — when false, internal links (same host as home_url) are
     * dropped during extraction. Defaults to true.
     */
    public bool $checkInternal = true;

    /**
     * `check.external` — when false, external links are dropped during extraction.
     * Defaults to true.
     */
    public bool $checkExternal = true;

    /**
     * `check.mixed_content` — when true, each link carries an `is_mixed_content`
     * boolean (true if the page is served over https but the link points to
     * http). When false, the field is omitted entirely. Defaults to true.
     */
    public bool $checkMixedContent = true;

    /**
     * Raw target list for type=page: array of `{source_type, source_id}` entries.
     * Kept verbatim from the payload so calculateExclusion can filter by
     * post_type without re-reading the request body. Ignored for type=full.
     *
     * @var array<int, array{source_type: string, source_id: int}>
     */
    public array $target = [];

    public string $manifestPath = '';

    public function __construct(string $mrid)
    {
        $this->mrid = $mrid;
    }

    public function setType(string $postType): self
    {
        $this->postType = $postType;

        return $this;
    }

    /**
     * Scan-level config (shared across all parts). Per-part resolution lives in
     * calculateExclusion() so a part can decide it has nothing to scan.
     *
     * @param \stdClass|array $payload
     * @return $this
     * @throws \InvalidArgumentException
     */
    public function setPayload($payload): self
    {
        // Root-level fields: identity and what-to-scan.
        $this->siteBrokenLinkScan = (int)data_get($payload, 'site_broken_link_scan', 0);
        $this->type = $this->resolveType(data_get($payload, 'type'));
        $this->target = $this->validateTarget((array)data_get($payload, 'target', []));

        if ($this->siteBrokenLinkScan <= 0) {
            throw new \InvalidArgumentException('site_broken_link_scan is required');
        }

        // How-to-scan: nested under `settings.*`.
        $this->mode = $this->resolveMode(data_get($payload, 'settings.mode'));
        $this->maxPages = $this->validateMaxPages(data_get($payload, 'settings.max_pages'));
        $this->resourceTypes = $this->validateResourceTypes(data_get($payload, 'settings.resource_types'));
        $this->excludedSourceTypes = $this->validateExcludedSourceTypes(data_get($payload, 'settings.excluded.source_types', []));
        $this->excludedUrlPatterns = $this->compilePatterns((array)data_get($payload, 'settings.excluded.url_patterns', []));

        // settings.check.* flags default to true (all checks enabled). Missing
        // keys keep the defaults so older API versions that don't send `check`
        // behave as they used to.
        $this->checkInternal = $this->resolveBool(data_get($payload, 'settings.check.internal'), true);
        $this->checkExternal = $this->resolveBool(data_get($payload, 'settings.check.external'), true);
        $this->checkMixedContent = $this->resolveBool(data_get($payload, 'settings.check.mixed_content'), true);

        return $this;
    }

    /**
     * Local NDJSON path. Keyed by site_broken_link_scan + post_type so every
     * part owns an independent file (Backups-style: one ZIP per part).
     */
    public function setManifestPath(): self
    {
        $this->manifestPath = Storage::disk('scans')->path(sprintf(
            'blc-%d-%s.ndjson',
            $this->siteBrokenLinkScan,
            $this->postType
        ));

        return $this;
    }

    /**
     * Decide whether this part runs. Emits ManagerBrokenLinkPartUpdated with
     * STATUS_EXCLUDED for parts that won't dispatch; leaves PENDING ones alone.
     *
     * Exclusion sources:
     *   - built-in EXCLUDED_SOURCE_TYPES (deny list)
     *   - excluded.source_types (user-provided)
     *   - type=page → only post-types referenced in the target array are kept;
     *                 source_ids are validated (published) before keeping them.
     */
    public function calculateExclusion(): self
    {
        if (in_array($this->postType, self::EXCLUDED_SOURCE_TYPES, true)) {
            return $this->markAs(
                ManagerBrokenLinkPartUpdated::STEP_SCAN,
                ManagerBrokenLinkPartUpdated::STATUS_EXCLUDED,
                false,
                ['reason' => 'source_type_builtin_excluded']
            );
        }

        if (in_array($this->postType, $this->excludedSourceTypes, true)) {
            return $this->markAs(
                ManagerBrokenLinkPartUpdated::STEP_SCAN,
                ManagerBrokenLinkPartUpdated::STATUS_EXCLUDED,
                false,
                ['reason' => 'source_type_user_excluded']
            );
        }

        if ($this->type === self::TYPE_PAGE) {
            $candidateIds = [];
            foreach ($this->target as $entry) {
                if (($entry['source_type'] ?? '') !== $this->postType) {
                    continue;
                }
                $candidateIds[] = (int)$entry['source_id'];
            }

            if (empty($candidateIds)) {
                return $this->markAs(
                    ManagerBrokenLinkPartUpdated::STEP_SCAN,
                    ManagerBrokenLinkPartUpdated::STATUS_EXCLUDED,
                    false,
                    ['reason' => 'type_page_no_targets_for_source_type']
                );
            }

            $resolved = [];
            foreach ($candidateIds as $postId) {
                if ($postId <= 0) {
                    continue;
                }
                if (\get_post_status($postId) !== 'publish') {
                    continue;
                }
                if ((string)\get_post_type($postId) !== $this->postType) {
                    continue;
                }
                $resolved[] = $postId;
            }

            if (empty($resolved)) {
                return $this->markAs(
                    ManagerBrokenLinkPartUpdated::STEP_SCAN,
                    ManagerBrokenLinkPartUpdated::STATUS_EXCLUDED,
                    false,
                    ['reason' => 'type_page_targets_unresolved']
                );
            }

            $this->postIds = array_values(array_unique($resolved));
            return $this;
        }

        // TYPE_FULL — paginated by ProcessBrokenLinkBatchJob, no upfront resolution.
        return $this;
    }

    /**
     * Step/status transition + event emission. Mirrors ScanPart::markAs but with
     * the {step, status} split (Imunify pattern). Idempotent: if the new pair
     * matches the current one, nothing happens.
     */
    public function markAs(string $step, string $status, bool $emitEvent = true, array $extra = []): self
    {
        if ($this->step === $step && $this->status === $status) {
            return $this;
        }

        $this->step = $step;
        $this->status = $status;

        Log::debug('BrokenLinkPart: Status changed', [
            'source_type' => $this->postType,
            'step' => $step,
            'status' => $status,
            'site_broken_link_scan' => $this->siteBrokenLinkScan,
        ]);

        if ($emitEvent) {
            event(ManagerBrokenLinkPartUpdated::forPart($this, $step, $status, $extra));
        }

        if (in_array($status, [
            ManagerBrokenLinkPartUpdated::STATUS_EXCLUDED,
            ManagerBrokenLinkPartUpdated::STATUS_DONE,
        ], true)) {
            $this->cleanFiles();
        }

        return $this;
    }

    /**
     * Contract-compatible failure handler (`ManifestablePartContract::markAsFailed`).
     * The first arg is the **step** (not a status) — BLC always sets failure
     * status to STATUS_FAILED, the step carries the information about *where*
     * the failure happened (scan vs upload).
     *
     * @param string $step One of ManagerBrokenLinkPartUpdated::STEP_*
     * @param \Throwable|null $e
     */
    public function markAsFailed($step, \Throwable $e = null)
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

        $this->markAs($step, ManagerBrokenLinkPartUpdated::STATUS_FAILED, true, $extra);
    }

    public function createManifest()
    {
        return BrokenLinkManifest::create($this);
    }

    public function getManifestFailedStatus()
    {
        return ManagerBrokenLinkPartUpdated::STATUS_FAILED;
    }

    public function isCancelled()
    {
        return BrokenLink::isCancelled($this->siteBrokenLinkScan);
    }

    private function cleanFiles(): void
    {
        if ($this->manifestPath === '') {
            return;
        }

        try {
            (new NdjsonWriter($this->manifestPath))->delete();
        } catch (\Throwable $e) {
            Log::debug('BrokenLinkPart: cleanFiles failed', [
                'source_type' => $this->postType,
                'path' => $this->manifestPath,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Tolerant boolean parser for payload flags. Treats true/1/'1'/'true' as
     * truthy and false/0/'0'/'false' as falsy; anything else (including null,
     * missing) falls back to $default. Keeps a stray string ('yes', 'no', …)
     * from silently flipping the flag.
     *
     * @param mixed $value
     */
    private function resolveBool($value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        if (is_string($value)) {
            $lower = strtolower(trim($value));
            if ($lower === 'true' || $lower === '1') {
                return true;
            }
            if ($lower === 'false' || $lower === '0') {
                return false;
            }
        }
        return $default;
    }

    /**
     * Silent fallback to TYPE_FULL keeps a stray/legacy value from aborting a
     * scan — the broadest path is the safe default.
     */
    private function resolveType($value): string
    {
        return in_array($value, [self::TYPE_FULL, self::TYPE_PAGE], true)
            ? (string)$value
            : self::TYPE_FULL;
    }

    /**
     * Silent fallback to MODE_PLAIN keeps a stray/legacy value from aborting a
     * scan — the lightweight path is the safe default.
     */
    private function resolveMode($value): string
    {
        return in_array($value, [self::MODE_PLAIN, self::MODE_RENDER], true)
            ? (string)$value
            : self::MODE_PLAIN;
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function validateMaxPages($value): int
    {
        if ($value === null) {
            throw new \InvalidArgumentException('settings.max_pages is required');
        }
        if (!is_numeric($value) || (int)$value < 1) {
            throw new \InvalidArgumentException('settings.max_pages must be a positive integer');
        }

        return (int)$value;
    }

    /**
     * Forward-compat: keep only types the plugin currently extracts and silently
     * drop the rest. Lets the API roll out new types (og_image, canonical, future
     * additions) without breaking older plugin versions. Empty request bodies are
     * still rejected — that's a malformed request, not a forward-compat case.
     *
     * @return string[]
     * @throws \InvalidArgumentException
     */
    private function validateResourceTypes($value): array
    {
        if (!is_array($value) || empty($value)) {
            throw new \InvalidArgumentException('settings.resource_types must be a non-empty array');
        }

        return array_values(array_intersect($value, self::SUPPORTED_RESOURCE_TYPES));
    }

    /**
     * @return string[]
     * @throws \InvalidArgumentException
     */
    private function validateExcludedSourceTypes($value): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException('settings.excluded.source_types must be an array');
        }

        $clean = [];
        foreach ($value as $entry) {
            if (!is_string($entry) || $entry === '') {
                throw new \InvalidArgumentException('settings.excluded.source_types entries must be non-empty strings');
            }
            $clean[] = $entry;
        }

        return $clean;
    }

    /**
     * Normalize the target list. Each entry must be `{source_type, source_id}`.
     * Empty list is valid (only meaningful for type=page; ignored for type=full).
     *
     * @return array<int, array{source_type: string, source_id: int}>
     * @throws \InvalidArgumentException
     */
    private function validateTarget(array $target): array
    {
        $clean = [];
        foreach ($target as $entry) {
            $sourceType = (string)data_get($entry, 'source_type', '');
            $sourceId = (int)data_get($entry, 'source_id', 0);

            if ($sourceType === '' || $sourceId <= 0) {
                throw new \InvalidArgumentException('target entries require non-empty source_type and positive source_id');
            }

            $clean[] = [
                'source_type' => $sourceType,
                'source_id' => $sourceId,
            ];
        }

        return $clean;
    }

    /**
     * Smoke-test each PCRE before storing it. Failing fast here keeps LinkExtractor
     * from poisoning every batch row at runtime.
     *
     * @param string[] $patterns
     * @return string[]
     * @throws \InvalidArgumentException
     */
    private function compilePatterns(array $patterns): array
    {
        $compiled = [];
        foreach ($patterns as $pattern) {
            $delimited = '~' . str_replace('~', '\\~', (string)$pattern) . '~u';
            if (@preg_match($delimited, '') === false) {
                throw new \InvalidArgumentException('settings.excluded.url_patterns contains invalid regex: ' . (string)$pattern);
            }
            $compiled[] = $delimited;
        }

        return $compiled;
    }
}
