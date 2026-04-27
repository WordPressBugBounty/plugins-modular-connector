<?php

namespace Modular\Connector\Http\Controllers;

use Modular\Connector\Jobs\Users\UserDeleteJob;
use Modular\Connector\Jobs\Users\UserIndexJob;
use Modular\Connector\Jobs\Users\UserStoreJob;
use Modular\Connector\Jobs\Users\UserUpdateJob;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Response;
use Modular\SDK\Objects\SiteRequest;
use function Modular\ConnectorDependencies\dispatch;

class UserController
{
    /**
     * @param SiteRequest $modularRequest
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(SiteRequest $modularRequest)
    {
        dispatch(new UserIndexJob($modularRequest->request_id));

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
        dispatch(new UserStoreJob($modularRequest->request_id, $modularRequest->body));

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
        dispatch(new UserUpdateJob($modularRequest->request_id, $modularRequest->body));

        return Response::json([
            'success' => 'OK',
        ]);
    }

    /**
     * @param SiteRequest $modularRequest
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy(SiteRequest $modularRequest)
    {
        dispatch(new UserDeleteJob($modularRequest->request_id, $modularRequest->body));

        return Response::json([
            'success' => 'OK',
        ]);
    }
}
