<?php

namespace Modular\Connector\Http\Controllers;

use Modular\Connector\Optimizer\Jobs\ManagerOptimizationInformationUpdateJob;
use Modular\Connector\Optimizer\Jobs\ManagerOptimizationProcessJob;
use Modular\ConnectorDependencies\Illuminate\Routing\Controller;
use Modular\ConnectorDependencies\Illuminate\Support\Collection;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Response;
use Modular\SDK\Objects\SiteRequest;
use function Modular\ConnectorDependencies\data_get;
use function Modular\ConnectorDependencies\dispatch;

class OptimizationController extends Controller
{
    /**
     * @param SiteRequest $modularRequest
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(SiteRequest $modularRequest)
    {
        dispatch(new ManagerOptimizationInformationUpdateJob($modularRequest->request_id, $modularRequest->body));

        return Response::json([
            'success' => 'OK',
        ]);
    }

    /**
     * @param SiteRequest $modularRequest
     * @return \Illuminate\Http\JsonResponse
     */
    public function optimizeDatabase(SiteRequest $modularRequest)
    {
        $payload = $modularRequest->body;

        $optimizations = Collection::make(data_get($payload, 'optimizations', []));

        Log::debug('The following optimizations will be applied', $optimizations->toArray());

        foreach ($optimizations as $type) {
            dispatch(new ManagerOptimizationProcessJob($modularRequest->request_id, $payload, $type));
        }

        return Response::json([
            'success' => 'OK',
        ]);
    }
}
