<?php

namespace Modular\Connector\Http\Controllers;

use Modular\Connector\Jobs\RedirectsSyncJob;
use Modular\ConnectorDependencies\Illuminate\Routing\Controller;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Response;
use Modular\SDK\Objects\SiteRequest;
use function Modular\ConnectorDependencies\dispatch;

class RedirectsController extends Controller
{
    /**
     * Hand off the redirect payload to RedirectsSyncJob and acknowledge
     * immediately. The job dispatches a `manager.redirection.synced` event with
     * the outcome (status + error), matching the async pattern used by the
     * Optimization, Backup, BrokenLink and Manager controllers.
     */
    public function sync(SiteRequest $modularRequest)
    {
        dispatch(new RedirectsSyncJob($modularRequest->request_id, $modularRequest->body));

        return Response::json(['success' => 'OK']);
    }
}
