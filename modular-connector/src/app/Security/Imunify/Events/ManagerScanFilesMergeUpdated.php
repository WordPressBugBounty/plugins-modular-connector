<?php

namespace Modular\Connector\Security\Imunify\Events;

use Modular\Connector\Events\AbstractEvent;

/**
 * Dedicated event for the local merge step. Covers every transition (in_progress,
 * done, failed) with a single class so API receives `manager.scan.files.merge.updated`
 * with a payload reduced to `{site_scan, status, ...extras}` — the `type` and `step`
 * fields are encoded in the event class name itself, not duplicated in the payload.
 *
 * Wired to two listeners:
 *   - HookEventListener forwards it to API.
 *   - ScanFilesSubmitDispatcherListener filters status=done and owns the
 *     "merge → submit" pipeline transition.
 *
 * Internal-only properties (`name`, `manifestPath`, `totalItems`) carry the data
 * the dispatcher listener needs to build the next job. They live outside of
 * `AbstractEvent::$payload` so HookSendEventJob does not forward them to API.
 *
 * Use the named factories below instead of `new` — they enforce the per-status
 * shape (which extras belong with which status, which internal props are set).
 */
class ManagerScanFilesMergeUpdated extends AbstractEvent
{
    /**
     * @var int
     */
    public $siteScan;

    /**
     * @var string One of ManagerScanPartUpdated::STATUS_* (in_progress, done, failed).
     */
    public $status;

    /**
     * @var string|null Internal — populated by `done()` for the dispatcher listener.
     */
    public $name;

    /**
     * @var string|null Internal — populated by `done()`.
     */
    public $manifestPath;

    /**
     * @var int Internal — populated by `done()`.
     */
    public $totalItems = 0;

    /**
     * @param string $mrid
     * @param int $siteScan
     * @param string $status
     * @param array $extra Extra fields to merge into the webhook payload.
     */
    public function __construct($mrid, $siteScan, $status, array $extra = [])
    {
        $this->siteScan = (int) $siteScan;
        $this->status = $status;

        parent::__construct($mrid, array_merge([
            'site_scan' => $this->siteScan,
            'status' => $status,
        ], $extra));
    }

    /**
     * Merge has started. No extras yet.
     *
     * @param string $mrid
     * @param int $siteScan
     * @return self
     */
    public static function inProgress($mrid, $siteScan): self
    {
        return new self($mrid, $siteScan, ManagerScanPartUpdated::STATUS_IN_PROGRESS);
    }

    /**
     * Local merge finished. Carries `total_items` to the webhook and populates the
     * internal props so the dispatcher listener can build SubmitToImunifyJob.
     *
     * @param string $mrid
     * @param string $name
     * @param int $siteScan
     * @param string $manifestPath
     * @param int $totalItems
     * @return self
     */
    public static function done($mrid, $name, $siteScan, $manifestPath, $totalItems): self
    {
        $event = new self($mrid, $siteScan, ManagerScanPartUpdated::STATUS_DONE, [
            'total_items' => (int) $totalItems,
        ]);

        $event->name = $name;
        $event->manifestPath = $manifestPath;
        $event->totalItems = (int) $totalItems;

        return $event;
    }

    /**
     * Merge failed. Carries the error message to API.
     *
     * @param string $mrid
     * @param int $siteScan
     * @param string $error
     * @return self
     */
    public static function failed($mrid, $siteScan, $error): self
    {
        return new self($mrid, $siteScan, ManagerScanPartUpdated::STATUS_FAILED, [
            'error' => (string) $error,
        ]);
    }
}
