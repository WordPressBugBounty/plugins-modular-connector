<?php

namespace Modular\Connector\Http\Controllers;

use Modular\Connector\Jobs\RestorationSetupJob;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Response;
use Modular\SDK\Objects\SiteRequest;
use function Modular\ConnectorDependencies\dispatch;

class RestorationController
{
    /**
     * @param SiteRequest $modularRequest
     * @return \Illuminate\Http\JsonResponse
     */
    public function __invoke(SiteRequest $modularRequest)
    {
        dispatch(new RestorationSetupJob($modularRequest->request_id, $modularRequest->body));

        return Response::json([
            'success' => 'OK',
        ]);
    }
}
