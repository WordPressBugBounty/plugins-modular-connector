<?php

namespace Modular\Connector\BrokenLinks\Ndjson\Events;

use Modular\Connector\BrokenLinks\Ndjson\BrokenLinkPart;
use Modular\Connector\Events\AbstractEvent;

/**
 * Per-part transition. Mirrors `ManagerScanPartUpdated` (Imunify driver) by
 * splitting the wire payload into `step` + `status` instead of compound statuses
 * like `upload_pending` / `uploading`:
 *
 *   step ∈ {scan, upload}
 *   status ∈ {pending, in_progress, done, failed, excluded}
 *
 * State machine per part:
 *
 *   step=scan,   status=pending      → initial (only seen in PartsCalculated)
 *   step=scan,   status=excluded     → terminal up-front (calculateExclusion)
 *   step=scan,   status=in_progress  → ProcessBrokenLinkBatchJob first tick
 *   step=scan,   status=failed       → ProcessBrokenLinkBatchJob::failed()
 *   step=upload, status=pending      → batch loop finished with links — dispatcher seam
 *   step=upload, status=in_progress  → UploadBrokenLinkJob before PUT
 *   step=upload, status=done         → terminal success (also the no-links shortcut)
 *   step=upload, status=failed       → UploadBrokenLinkJob::failed()
 *
 * Terminal markers: `status ∈ {done, excluded, failed}`. `done` is always emitted
 * on `step=upload` so the API has a single canonical terminal to watch for
 * happy-path completion (with or without an actual S3 upload).
 */
class ManagerBrokenLinkPartUpdated extends AbstractEvent
{
    public const STEP_SCAN = 'scan';
    public const STEP_UPLOAD = 'upload';

    public const STATUS_PENDING = 'pending';
    public const STATUS_EXCLUDED = 'excluded';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';

    /**
     * In-process side-channel: the BrokenLinkPart that emitted the transition,
     * so the dispatcher listener can dispatch the next job in the pipeline
     * without reconstructing the part from the wire payload.
     *
     * HookSendEventJob serializes only `mrid` + `payload`, so this object never
     * leaks to the webhook.
     *
     * @var BrokenLinkPart
     */
    public $part;

    public function __construct(BrokenLinkPart $part, string $step, string $status, array $extra = [])
    {
        $this->part = $part;

        $data = [
            'site_broken_link_scan' => $part->siteBrokenLinkScan,
            'source_type' => $part->postType,
            'step' => $step,
            'status' => $status,
            'total_sources' => $part->processedCount,
            'total_links' => $part->totalLinks,
        ];

        if (!empty($extra)) {
            $data = array_merge($data, $extra);
        }

        parent::__construct($part->mrid, $data);
    }

    public static function forPart(BrokenLinkPart $part, string $step, string $status, array $extra = []): self
    {
        return new self($part, $step, $status, $extra);
    }
}
