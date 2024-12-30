<?php

namespace Modular\Connector\Http\Controllers;

use Modular\Connector\Facades\WooCommerce;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Response;
use Modular\SDK\Objects\SiteRequest;

class WooCommerceController
{
    /**
     * @param SiteRequest $modularRequest
     * @return \Illuminate\Http\JsonResponse
     */
    public function __invoke(SiteRequest $modularRequest)
    {
        $payload = $modularRequest->body;

        if (!WooCommerce::isActive() || !WooCommerce::hasMinimumVersion('7.0.0')) {
            return Response::json();
        }


        return Response::json(WooCommerce::getAnalytics($payload));
    }
}
