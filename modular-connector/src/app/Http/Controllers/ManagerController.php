<?php

namespace Modular\Connector\Http\Controllers;

use Modular\Connector\Jobs\ManagerInstallJob;
use Modular\Connector\Jobs\ManagerManageItemJob;
use Modular\Connector\Jobs\ManagerUpdateJob;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Response;
use Modular\ConnectorDependencies\Illuminate\Support\Str;
use Modular\SDK\Objects\SiteRequest;
use function Modular\ConnectorDependencies\dispatch;

class ManagerController
{
    /**
     * @param SiteRequest $modularRequest
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(SiteRequest $modularRequest)
    {
        dispatch(new ManagerUpdateJob($modularRequest->request_id));

        return Response::json([
            'success' => 'OK',
        ]);
    }

    /**
     * @param SiteRequest $modularRequest
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(SiteRequest $modularRequest)
    {
        dispatch(new ManagerInstallJob($modularRequest->request_id, $modularRequest->body));

        return Response::json([
            'success' => 'OK',
        ]);
    }

    /**
     * @param SiteRequest $modularRequest
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(SiteRequest $modularRequest)
    {
        $type = Str::replace('manager.', '', $modularRequest->type);

        dispatch(new ManagerManageItemJob($modularRequest->request_id, $modularRequest->body, $type));

        return Response::json([
            'success' => 'OK',
        ]);
    }
}
