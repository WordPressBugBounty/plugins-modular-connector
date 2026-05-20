<?php

namespace Modular\Connector\Security\Imunify\Listeners;

use Modular\Connector\Security\Imunify\Events\ManagerScanFilesMergeUpdated;
use Modular\Connector\Security\Imunify\Events\ManagerScanPartUpdated;
use Modular\Connector\Security\Imunify\Jobs\SubmitToImunifyJob;
use function Modular\ConnectorDependencies\dispatch;

/**
 * Pipeline step: react to a successful local merge by dispatching the submit job
 * that calls Imunify's `/scan/start`. With polling driving the rest of the flow,
 * the merge → submit transition is direct — no intermediate "upload manifest to
 * API" step (API no longer needs a copy of the consolidated manifest; the plugin
 * resolves `file_index → path/checksum` locally during the poll loop and uploads
 * only the per-file verdict NDJSON at the end via `ProcessScanResultsUploadJob`).
 *
 * Filters on `status=done` so in_progress / failed transitions don't trigger
 * the next stage — those are pure status notifications for API.
 */
class ScanFilesSubmitDispatcherListener
{
    /**
     * @param ManagerScanFilesMergeUpdated $event
     * @return void
     */
    public function handle(ManagerScanFilesMergeUpdated $event)
    {
        if ($event->status !== ManagerScanPartUpdated::STATUS_DONE) {
            return;
        }

        dispatch(new SubmitToImunifyJob(
            $event->mrid,
            $event->name,
            $event->siteScan,
            $event->manifestPath,
            $event->totalItems
        ));
    }
}
