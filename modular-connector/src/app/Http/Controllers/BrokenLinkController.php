<?php

namespace Modular\Connector\Http\Controllers;

use Modular\Connector\BrokenLinks\Facades\BrokenLink;
use Modular\Connector\BrokenLinks\Ndjson\Jobs\StartBrokenLinkScanJob;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Response;
use Modular\SDK\Objects\SiteRequest;
use function Modular\ConnectorDependencies\dispatch;

class BrokenLinkController
{
    public function scan(SiteRequest $modularRequest)
    {
        dispatch(new StartBrokenLinkScanJob($modularRequest->request_id, $modularRequest->body));

        return Response::json(['success' => 'OK']);
    }

    public function cancel(SiteRequest $modularRequest)
    {
        BrokenLink::cancel($modularRequest->body->site_broken_link_scan);

        return Response::json(['success' => 'OK']);
    }
}
