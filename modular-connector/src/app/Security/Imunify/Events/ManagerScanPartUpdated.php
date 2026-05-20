<?php

namespace Modular\Connector\Security\Imunify\Events;

use Modular\Connector\Events\AbstractEvent;
use Modular\Connector\Security\Imunify\ScanPart;

/**
 * Single event class for every scan-domain part transition emitted to the API.
 *
 * Two distinct shapes share the same wire format `manager.scan.part.updated`:
 *
 *   1. Per-disk manifest progress — build via `forPart(ScanPart)`.
 *      type ∈ {core, plugins, themes, mu_plugins, content, uploads}, step = manifest.
 *
 *   2. Aggregated flow progress — build via `forFiles(...)` or `forDatabase(...)`.
 *      type ∈ {files, database}, step ∈ {manifest, scan, quarantine, cleanup, restore}.
 *
 * The merge step has moved to its own dedicated event
 * (`ManagerScanFilesMergeUpdated`).
 */
class ManagerScanPartUpdated extends AbstractEvent
{
    public const TYPE_FILES = 'files';
    public const TYPE_DATABASE = 'database';

    public const STEP_MANIFEST = 'manifest';
    public const STEP_SCAN = 'scan';

    public const STATUS_PENDING = 'pending';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';
    public const STATUS_EXCLUDED = 'excluded';

    /**
     * In-process channel for per-disk events so coordination listeners can act on the
     * actual ScanPart without having to reconstruct it from the wire payload.
     *
     * Only the parent's `mrid` + `payload` are forwarded to the API by
     * HookSendEventJob, so this property never leaks to the webhook.
     *
     * @var ScanPart|null Set only by forPart(); null for aggregate events.
     */
    public $part;

    /**
     * Prefer the named factories below. This constructor exists to be invoked by
     * those factories with an already-built payload.
     *
     * @param string $mrid
     * @param array $payload Must contain {site_scan, type, step, status} at minimum.
     * @param ScanPart|null $part In-process side-channel for coordination listeners.
     */
    public function __construct($mrid, array $payload, ScanPart $part = null)
    {
        $this->part = $part;

        parent::__construct($mrid, $payload);
    }

    /**
     * Per-disk manifest event. mrid/site_scan/type/offset/total_items all come from
     * the part. step is always `manifest` — that is the only phase a ScanPart goes
     * through individually before being merged.
     *
     * @param ScanPart $part
     * @param string $status One of STATUS_*
     * @param array $extra Merged into the payload (e.g. ['error' => ['message' => …]])
     * @return self
     */
    public static function forPart(ScanPart $part, $status, array $extra = []): self
    {
        $payload = array_merge([
            'site_scan' => $part->siteScan,
            'type' => $part->type,
            'step' => self::STEP_MANIFEST,
            'status' => $status,
            'offset' => $part->offset,
            'total_items' => $part->totalItems,
        ], $extra);

        return new self($part->mrid, $payload, $part);
    }

    /**
     * Aggregate event for the file scan flow (type=files). Used by every job that
     * reports manifest/scan progress on the file side.
     *
     * @param string $mrid
     * @param int $siteScan
     * @param string $step One of STEP_*
     * @param string $status One of STATUS_*
     * @param array $data Optional extras (error, summary, threat_count, …).
     *                    `imunify_job_id` is intentionally never carried — API
     *                    already knows it from the `site_scan`.
     * @return self
     */
    public static function forFiles($mrid, $siteScan, $step, $status, array $data = []): self
    {
        return new self($mrid, array_merge([
            'site_scan' => $siteScan,
            'type' => self::TYPE_FILES,
            'step' => $step,
            'status' => $status,
        ], $data));
    }

    /**
     * Aggregate event for the database scan flow (type=database). Used by the DB
     * scan job to report manifest/scan progress.
     *
     * @param string $mrid
     * @param int $siteScan
     * @param string $step One of STEP_*
     * @param string $status One of STATUS_*
     * @param array $data Optional extras (error, summary, …).
     *                    `imunify_job_id` is intentionally never carried here — it
     *                    is broadcast via `ManagerScanProgressUpdated::startedDatabase`
     *                    once `/scan/database/start` returns the id.
     * @return self
     */
    public static function forDatabase($mrid, $siteScan, $step, $status, array $data = []): self
    {
        return new self($mrid, array_merge([
            'site_scan' => $siteScan,
            'type' => self::TYPE_DATABASE,
            'step' => $step,
            'status' => $status,
        ], $data));
    }

    /**
     * @param string $status
     * @return bool
     */
    public static function isManifestTerminal($status)
    {
        return in_array($status, [
            self::STATUS_DONE,
            self::STATUS_EXCLUDED,
            self::STATUS_FAILED,
        ], true);
    }
}
