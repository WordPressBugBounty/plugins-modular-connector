<?php

namespace Modular\Connector\Security\Imunify\Events;

use Modular\Connector\Events\AbstractEvent;

/**
 * Terminal failure of a scan flow. Step-agnostic — emitted whenever a flow dies
 * mid-flight, regardless of which step (manifest, scan, quarantine, …) was running.
 *
 * Per-flow: files and database are independent. A cancellation of one flow does
 * not stop the other; use the matching factory.
 *
 * Wired to two listeners:
 *   - HookEventListener forwards `manager.scan.cancelled` to API.
 *   - ScanCleanupListener wipes the per-flow cache bucket so straggler jobs cannot
 *     act on stale secrets or coordination state.
 *
 * Payload shape: `{site_scan, type, error}`. The Imunify job id is intentionally
 * omitted — API already knows it from the `site_scan`.
 */
class ManagerScanCancelled extends AbstractEvent
{
    /**
     * @var int Public side-channel so listeners avoid digging into the payload.
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
     * @param string $error
     */
    public function __construct($mrid, $siteScan, $type, $error)
    {
        $this->siteScan = (int) $siteScan;
        $this->type = $type;

        parent::__construct($mrid, [
            'site_scan' => $this->siteScan,
            'type' => $type,
            'error' => (string) $error,
        ]);
    }

    /**
     * @param string $mrid
     * @param int $siteScan
     * @param string $error Short tag (e.g. `no_manifest_data`, `poll_failed`) or message.
     * @return self
     */
    public static function forFiles($mrid, $siteScan, $error): self
    {
        return new self($mrid, $siteScan, ManagerScanPartUpdated::TYPE_FILES, $error);
    }

    /**
     * @param string $mrid
     * @param int $siteScan
     * @param string $error
     * @return self
     */
    public static function forDatabase($mrid, $siteScan, $error): self
    {
        return new self($mrid, $siteScan, ManagerScanPartUpdated::TYPE_DATABASE, $error);
    }
}
