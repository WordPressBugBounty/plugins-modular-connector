<?php

namespace Modular\Connector\Security\Imunify\Events;

use Modular\Connector\Events\AbstractEvent;

/**
 * Terminal success of a scan flow. Symmetric to `ManagerScanCancelled` —
 * step-agnostic, per-flow.
 *
 * Files flow: emitted by `PollImunifyJob::emitDone()` once polling reaches
 * `is_complete=true`. Carries the verdict summary built from the final `files`
 * payload (clean/malicious/suspicious/infected counts).
 *
 * Database flow: emitted by `ScanDbJob::completeScan()` once
 * GET /jobs/{id}/db-status returns `status=completed` and the follow-up
 * `/db-results` fetch returns the threats list inline.
 *
 * Wired to two listeners:
 *   - HookEventListener forwards `manager.scan.completed` to API.
 *   - ScanCleanupListener wipes the per-flow cache bucket.
 *
 * Payload shape (files): `{site_scan, type, total_files, summary}`.
 *
 * Findings (non-clean verdicts) ride on a separate NDJSON uploaded by
 * `ProcessScanResultsUploadJob` *before* this event fires — API already
 * knows about the file because it issued the presigned URL keyed by
 * `site_scan`, so no extra reference travels here. Clean files are not
 * transmitted; API derives their count from `summary.clean`.
 *
 * The Imunify job id is intentionally omitted — API already knows it from
 * the `site_scan`.
 */
class ManagerScanCompleted extends AbstractEvent
{
    /**
     * @var int
     */
    public $siteScan;

    /**
     * @var string ManagerScanPartUpdated::TYPE_FILES | TYPE_DATABASE.
     */
    public $type;

    /**
     * Prefer the named factories below.
     *
     * @param string $mrid
     * @param int $siteScan
     * @param string $type
     * @param array $payload Already-assembled payload (factories build it).
     */
    public function __construct($mrid, $siteScan, $type, array $payload)
    {
        $this->siteScan = (int)$siteScan;
        $this->type = $type;

        parent::__construct($mrid, $payload);
    }

    /**
     * @param string $mrid
     * @param int $siteScan
     * @param int $totalFiles Total file count carried into the scan (from manifest).
     * @param array $summary {clean, malicious, suspicious, infected, ...}
     * ºº     * @param string $status Verbatim provider status from Imunify (`completed`,
     *                      `failed`). Lets API distinguish a clean completion
     *                      from a salvaged partial scan: in both cases the
     *                      summary carries verdicts, but `status=failed` means
     *                      `sum(summary) < total_files` is expected.
     * @return self
     */
    public static function forFiles($mrid, $siteScan, $totalFiles, array $summary, $status): self
    {
        return new self($mrid, $siteScan, ManagerScanPartUpdated::TYPE_FILES, [
            'site_scan' => (int)$siteScan,
            'type' => ManagerScanPartUpdated::TYPE_FILES,
            'status' => (string)$status,
            'total_files' => (int)$totalFiles,
            'summary' => $summary,
        ]);
    }

    /**
     * @param string $mrid
     * @param int $siteScan
     * @param array $summary {tables_scanned, rows_submitted, threats_found, ...}
     * @return self
     */
    public static function forDatabase($mrid, $siteScan, array $summary): self
    {
        return new self($mrid, $siteScan, ManagerScanPartUpdated::TYPE_DATABASE, array_merge([
            'site_scan' => (int)$siteScan,
            'type' => ManagerScanPartUpdated::TYPE_DATABASE,
        ], $summary));
    }
}
