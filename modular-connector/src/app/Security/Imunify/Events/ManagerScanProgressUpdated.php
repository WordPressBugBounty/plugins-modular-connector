<?php

namespace Modular\Connector\Security\Imunify\Events;

use Modular\Connector\Events\AbstractEvent;

/**
 * Progress webhook (`manager.scan.progress.updated`) — in-flight progress of a
 * scan flow. The same class is reused by both file and database scans;
 * the `type` field disambiguates.
 *
 * Emission cadence is the caller's responsibility:
 *   - `SubmitToImunifyJob` emits `started(...)` once, right after `/scan/start`
 *     returns the Imunify `job_id`. This is the **only** event that carries
 *     `imunify_job_id` — API persists it from here.
 *   - `PollImunifyJob` emits `inProgress(...)` only if ≥ 2 minutes elapsed
 *     since the last emission (the `started` emission seeds the throttle).
 *
 * Terminal states travel on dedicated events: `ManagerScanCompleted` /
 * `ManagerScanCancelled` — so this event never carries a "done" / "failed".
 *
 * Payload shape:
 *   started     → `{site_scan, type, status: in_progress, imunify_job_id, stats}`
 *   in_progress → `{site_scan, type, status: in_progress, stats}`
 *
 * Progress percentage formula matches the reference plugin (scanner.php),
 * with 4-decimal precision instead of integer rounding so the UI can render
 * sub-percent movement (e.g. 80.4271%) on large scans where each tick only
 * shifts a few files out of tens of thousands:
 *
 *   sha256_done = total - needs_sha256
 *   progress    = round(sha256_done/total * 30 + has_verdict/total * 70, 4)
 *
 *   The SHA256 phase weighs 30%, the upload/verdict phase weighs 70% — most of
 *   the wall-clock is spent waiting for Imunify to classify files after hashes
 *   were submitted, so verdict progress dominates the user-facing percentage.
 */
class ManagerScanProgressUpdated extends AbstractEvent
{
    public const STATUS_IN_PROGRESS = 'in_progress';

    /**
     * @var int
     */
    public $siteScan;

    /**
     * Internal — prefer the named factories below.
     *
     * @param string $mrid
     * @param int $siteScan
     * @param array $payload Fully-assembled payload (the factories build it).
     */
    public function __construct($mrid, $siteScan, array $payload)
    {
        $this->siteScan = (int)$siteScan;

        parent::__construct($mrid, $payload);
    }

    /**
     * First emission of a scan flow, fired once `/scan/start` returns the Imunify
     * `job_id`. Carries the id so API can persist the binding between
     * `site_scan` and the provider's job. Subsequent polls use `inProgress(...)`
     * without the id (API already has it).
     *
     * Initial stats are the trivial state: every file needs sha256, nothing has
     * been uploaded or verdicted yet — no need to ask the caller.
     *
     * @param string $mrid
     * @param int $siteScan
     * @param string $type ManagerScanPartUpdated::TYPE_FILES | TYPE_DATABASE
     * @param string $imunifyJobId
     * @param int $totalFiles
     * @return self
     */
    public static function started($mrid, $siteScan, $type, $imunifyJobId, $totalFiles): self
    {
        return new self($mrid, $siteScan, [
            'site_scan' => (int)$siteScan,
            'type' => $type,
            'status' => self::STATUS_IN_PROGRESS,
            'imunify_job_id' => (string)$imunifyJobId,
            'stats' => self::buildStats($totalFiles, $totalFiles, 0, 0),
        ]);
    }

    /**
     * DB-specific `started` emission. Fired by `ScanDbJob` right after
     * `/scan/database/start` returns the Imunify job id. Carries the binding so
     * API can persist `imunify_job_id` for the DB scan (mirrors what `started(...)`
     * does for files).
     *
     * No `stats` block: DB scans don't have a file/row progress notion at this
     * point (rows are discovered + submitted in the same tick) and have no
     * follow-up `inProgress(...)` emission — they terminate within seconds via
     * `manager.scan.completed`.
     *
     * @param string $mrid
     * @param int $siteScan
     * @param string $imunifyJobId
     * @return self
     */
    public static function startedDatabase($mrid, $siteScan, $imunifyJobId): self
    {
        return new self($mrid, $siteScan, [
            'site_scan' => (int)$siteScan,
            'type' => ManagerScanPartUpdated::TYPE_DATABASE,
            'status' => self::STATUS_IN_PROGRESS,
            'imunify_job_id' => (string)$imunifyJobId,
        ]);
    }

    /**
     * Throttled progress emission during the polling loop. Does NOT carry
     * `imunify_job_id` — it was sent by `started(...)` and API has it.
     *
     * @param string $mrid
     * @param int $siteScan
     * @param string $type ManagerScanPartUpdated::TYPE_FILES | TYPE_DATABASE
     * @param int $totalFiles
     * @param int $needsSha256
     * @param int $needsUpload
     * @param int $hasVerdict
     * @return self
     */
    public static function inProgress($mrid, $siteScan, $type, $totalFiles, $needsSha256, $needsUpload, $hasVerdict): self
    {
        return new self($mrid, $siteScan, [
            'site_scan' => (int)$siteScan,
            'type' => $type,
            'status' => self::STATUS_IN_PROGRESS,
            'stats' => self::buildStats($totalFiles, $needsSha256, $needsUpload, $hasVerdict),
        ]);
    }

    /**
     * @param int $totalFiles
     * @param int $needsSha256
     * @param int $needsUpload
     * @param int $hasVerdict
     * @return array
     */
    private static function buildStats($totalFiles, $needsSha256, $needsUpload, $hasVerdict): array
    {
        $totalFiles = (int)$totalFiles;
        $needsSha256 = (int)$needsSha256;
        $hasVerdict = (int)$hasVerdict;

        // 4-decimal precision so the UI can show smooth fractional progress
        // (e.g. 80.4271%) instead of jumping in 1% steps when each tick only
        // moves a handful of files out of a large total.
        $progress = 0.0;
        if ($totalFiles > 0) {
            $sha256Done = max(0, $totalFiles - $needsSha256);
            $progress = round(($sha256Done / $totalFiles) * 30 + ($hasVerdict / $totalFiles) * 70, 4);
        }

        return [
            'total_files' => $totalFiles,
            'needs_sha256' => $needsSha256,
            'needs_upload' => (int)$needsUpload,
            'has_verdict' => $hasVerdict,
            'progress' => $progress,
        ];
    }
}
