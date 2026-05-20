<?php

namespace Modular\Connector\Http\Controllers;

use Modular\Connector\Security\Facades\Scan;
use Modular\Connector\Security\Imunify\Helpers\ConsolidatedManifest;
use Modular\Connector\Security\Imunify\Jobs\PollImunifyJob;
use Modular\Connector\Security\Imunify\Jobs\StartScanJob;
use Modular\ConnectorDependencies\Illuminate\Routing\Controller;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Response;
use Modular\SDK\Objects\SiteRequest;
use function Modular\ConnectorDependencies\data_get;
use function Modular\ConnectorDependencies\dispatch;

class SecurityController extends Controller
{
    /**
     * @param SiteRequest $modularRequest
     * @return \Modular\ConnectorDependencies\Illuminate\Http\JsonResponse
     */
    public function scan(SiteRequest $modularRequest)
    {
        $payload = $modularRequest->body;
        $requestId = $modularRequest->request_id;

        $previousScanName = data_get($payload, 'site_scan_cancel');

        if (!empty($previousScanName)) {
            Scan::cancel($previousScanName);
        }

        // Persist scan-time secrets and options here — before the dispatch —
        // so they are committed regardless of whether the queue write that
        // follows succeeds. Keeps the StartScanJob constructor pure (no
        // side-effects) and avoids unit tests that instantiate the job from
        // touching real cache state.
        if (!empty($payload->site_key)) {
            Scan::storeSiteKey((string)$payload->site_key);
        }

        Scan::storeScanOptions((int)$payload->site_scan, [
            'force_rescan' => isset($payload->force_rescan) ? (bool)$payload->force_rescan : false,
            'webhook_url' => isset($payload->webhook_url) ? (string)$payload->webhook_url : '',
            'webhook_secret' => isset($payload->webhook_secret) ? (string)$payload->webhook_secret : '',
            'max_poll_hours' => isset($payload->max_poll_hours) ? (int)$payload->max_poll_hours : 0,
        ]);

        dispatch(new StartScanJob($requestId, $payload));

        return Response::json(['success' => 'OK']);
    }

    /**
     * Kick endpoint: API calls this when polling appears stalled (e.g. wp-cron
     * or the loopback worker stopped firing overnight and the `PollImunifyJob`
     * chain died mid-flight). Dispatches a fresh `PollImunifyJob` with the
     * state API has persisted; if a chain is still alive the dispatch is
     * dropped silently by `PendingDispatch::shouldDispatch()` thanks to
     * `PollImunifyJob` implementing `ShouldBeUniqueUntilProcessing`.
     *
     * Payload (sent by API):
     *   - name                : scan name
     *   - site_scan           : int
     *   - site_action_item    : int (echoed back by event listeners)
     *   - imunify_file_job_id : provider job id (API persisted it from `started`)
     *   - total_files         : consolidated manifest size (API persisted it from
     *                           `ManagerScanFilesMergeUpdated::done`)
     *
     * The dispatched job revalidates everything on its own (isFilesCancelled,
     * manifest existence, site_key in cache); if state is gone the job exits
     * early or cancels the flow via `Scan::cancelFiles`.
     *
     * @param SiteRequest $modularRequest
     * @return \Modular\ConnectorDependencies\Illuminate\Http\JsonResponse
     */
    public function action(SiteRequest $modularRequest)
    {
        $payload = $modularRequest->body;
        $requestId = $modularRequest->request_id;

        $name = trim($payload->name, '"');
        $siteScan = (int)$payload->site_scan;
        $imunifyJobId = (string)$payload->imunify_file_job_id;
        $totalFiles = (int)$payload->total_files;

        dispatch(new PollImunifyJob(
            $requestId,
            $name,
            $siteScan,
            $imunifyJobId,
            ConsolidatedManifest::pathFor($name),
            $totalFiles
        ));

        return Response::json(['success' => 'OK']);
    }

    /**
     * @param SiteRequest $modularRequest
     * @return \Modular\ConnectorDependencies\Illuminate\Http\JsonResponse
     */
    public function cancel(SiteRequest $modularRequest)
    {
        Scan::cancel($modularRequest->body->name);

        return Response::json(['success' => 'OK']);
    }
}
