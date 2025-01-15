<?php

namespace Modular\Connector\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modular\Connector\Facades\Server;
use Modular\Connector\Facades\WhiteLabel;
use Modular\Connector\Jobs\Health\ManagerHealthDataJob;
use Modular\ConnectorDependencies\Illuminate\Routing\Controller;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Response;
use Modular\SDK\Objects\SiteRequest;
use function Modular\ConnectorDependencies\dispatch;

class ServerController extends Controller
{
    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function getInformation()
    {
        $information = Server::information();

        return Response::json($information);
    }

    /**
     * @param SiteRequest $modularRequest
     * @return \Illuminate\Http\JsonResponse
     */
    public function getHealth(SiteRequest $modularRequest)
    {
        $health = \WP_Site_Health::get_tests();

        $syncTests = $health['direct'];
        $syncTests = array_keys($syncTests);

        dispatch(new ManagerHealthDataJob($modularRequest->request_id, 'direct', $syncTests));

        $asyncTests = $health['async'];
        $asyncTests = array_keys($asyncTests);

        foreach ($asyncTests as $test) {
            dispatch(new ManagerHealthDataJob($modularRequest->request_id, 'async', [$test]));
        }

        return Response::json([
            'message' => 'Health data is being processed',
        ]);
    }

    /**
     * @return JsonResponse
     */
    public function getWhiteLabel()
    {
        dispatch(fn() => WhiteLabel::forget());

        return Response::json([
            'message' => 'White label data is being processed',
        ]);
    }
}
