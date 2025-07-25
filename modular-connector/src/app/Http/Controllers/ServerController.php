<?php

namespace Modular\Connector\Http\Controllers;

use Modular\Connector\Cache\Jobs\CacheClearJob;
use Modular\Connector\Facades\Server;
use Modular\Connector\Facades\WhiteLabel;
use Modular\Connector\Jobs\Health\ManagerHealthDataJob;
use Modular\Connector\Optimizer\Jobs\ManagerOptimizationInformationUpdateJob;
use Modular\ConnectorDependencies\Illuminate\Routing\Controller;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Cache;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Response;
use Modular\SDK\Objects\SiteRequest;
use function Modular\ConnectorDependencies\app;
use function Modular\ConnectorDependencies\data_get;
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

        dispatch(new ManagerOptimizationInformationUpdateJob($modularRequest->request_id, $modularRequest->body));

        return Response::json([
            'message' => 'Health data is being processed',
        ]);
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function getWhiteLabel()
    {
        dispatch(fn() => WhiteLabel::forget());

        return Response::json([
            'message' => 'White label data is being processed',
        ]);
    }

    /**
     * @param SiteRequest $modularRequest
     * @return \Illuminate\Http\JsonResponse
     */
    public function maintenance(SiteRequest $modularRequest)
    {
        $payload = $modularRequest->body;
        $enabled = data_get($payload, 'enabled', false);
        $title = data_get($payload, 'title');
        $description = data_get($payload, 'description');
        $withBranding = data_get($payload, 'with_branding', true);
        $background = data_get($payload, 'background', '#6308F7');
        $noindex = data_get($payload, 'noindex', false);

        $data = compact('enabled', 'title', 'description', 'withBranding', 'background', 'noindex');

        Cache::driver('wordpress')->forever('maintenance_mode', $data);
        dispatch(new CacheClearJob());

        return Response::json([
            'success' => 'OK',
        ]);
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function getLoopback()
    {
        // Don't lock up other requests while processing.
        session_write_close();

        $action = app()->getScheduleHook();

        dispatch(function () use ($action) {
            Log::debug('Running schedule hook', ['hook' => $action]);

            do_action($action);
        })->afterResponse(); // For AJAX request, we need to force close the connection to avoid the server hanging.

        return Response::json([
            'message' => 'Schedule hook is being processed',
            'hook' => $action,
        ]);
    }
}
