<?php

namespace Modular\Connector\BrokenLinks\Ndjson\Listeners;

use Modular\Connector\BrokenLinks\Ndjson\Events\ManagerBrokenLinkPartUpdated;
use Modular\Connector\BrokenLinks\Ndjson\Jobs\UploadBrokenLinkJob;
use function Modular\ConnectorDependencies\data_get;
use function Modular\ConnectorDependencies\dispatch;

/**
 * Bridges Process → Upload through an event. ProcessBrokenLinkBatchJob does not
 * know about UploadBrokenLinkJob; it emits `step=upload, status=pending` when
 * the batch loop finishes with links and this listener owns the transition.
 * Any other (step, status) pair is ignored, including the `step=upload,
 * status=done` shortcut emitted when totalLinks=0 (no upload to do).
 *
 * Mirrors ScanFilesSubmitDispatcherListener.
 */
class BrokenLinkUploadDispatcherListener
{
    public function handle(ManagerBrokenLinkPartUpdated $event): void
    {
        $step = data_get($event->payload, 'step');
        $status = data_get($event->payload, 'status');

        if ($step !== ManagerBrokenLinkPartUpdated::STEP_UPLOAD) {
            return;
        }
        if ($status !== ManagerBrokenLinkPartUpdated::STATUS_PENDING) {
            return;
        }

        dispatch(new UploadBrokenLinkJob($event->part));
    }
}
