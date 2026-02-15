<?php

namespace Modular\Connector\Http\Middleware;

use Modular\ConnectorDependencies\Illuminate\Http\Request;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Cache;

/**
 * Cache Authorization header for routes with {modular_request} parameter.
 *
 * This middleware preserves the Authorization header from Modular API requests
 * so it can be used throughout the request lifecycle.
 *
 * Only applies to routes with {modular_request} parameter (type=request from API).
 * Does NOT apply to: modular-connector.oauth, schedule.run, or default route.
 */
class CacheAuthorizationHeader
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param \Closure $next
     * @return mixed
     */
    public function handle(Request $request, \Closure $next)
    {
        $route = $request->route();

        $hasModularRequest = $route && $route->hasParameter('modular_request');

        if ($hasModularRequest) {
            if ($request->hasHeader('authorization')) {
                $authorization = $request->header('Authorization');

                Cache::driver('wordpress')->forever('header.authorization', $authorization);
            } else {
                Cache::driver('wordpress')->forget('header.authorization');
            }
        }

        return $next($request);
    }
}
