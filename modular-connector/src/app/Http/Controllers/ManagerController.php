<?php

namespace Modular\Connector\Http\Controllers;

use Modular\Connector\Concerns\NormalizesManagerPayload;
use Modular\Connector\Jobs\ManagerInstallJob;
use Modular\Connector\Jobs\ManagerManageItemJob;
use Modular\Connector\Jobs\ManagerUpdateJob;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Response;
use Modular\ConnectorDependencies\Illuminate\Support\Str;
use Modular\SDK\Objects\SiteRequest;
use function Modular\ConnectorDependencies\dispatch;

class ManagerController
{
    use NormalizesManagerPayload;

    /**
     * @param SiteRequest $modularRequest
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(SiteRequest $modularRequest)
    {
        $job = dispatch(new ManagerUpdateJob($modularRequest->request_id))->afterResponse();
        unset($job);

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
        $payload = $this->normalizeInstallPayload($modularRequest->body);

        dispatch(new ManagerInstallJob($modularRequest->request_id, $payload));

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
        $action = Str::replace('manager.', '', $modularRequest->type);
        $payload = $this->normalizeManagePayload($modularRequest->body);

        dispatch(new ManagerManageItemJob($modularRequest->request_id, $payload, $action));

        return Response::json([
            'success' => 'OK',
        ]);
    }
}
