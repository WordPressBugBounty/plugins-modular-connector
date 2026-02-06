<?php

namespace Modular\Connector\Http;

use Modular\ConnectorDependencies\Ares\Framework\Foundation\Contracts\RouteResolver;
use Modular\ConnectorDependencies\Illuminate\Http\Request;
use Modular\ConnectorDependencies\Illuminate\Routing\Route;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Cache;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use function Modular\ConnectorDependencies\app;

/**
 * Modular Connector route resolver.
 *
 * Resolves routes for Modular requests based on type (request, oauth, lb).
 *
 * This implementation uses dependency injection for secure internal routing.
 * External code cannot intercept route resolution.
 */
class ModularRouteResolver implements RouteResolver
{
    /**
     * Get type from request (header XOR query, never both).
     *
     * @param Request $request
     * @return string|null
     */
    private function getTypeFromRequest(Request $request): ?string
    {
        $hasQuery = $request->has('type');
        $hasHeader = $request->hasHeader('x-mo-type');

        // XOR: one or the other, never both (already validated in isDirectRequest)
        if ($hasQuery) {
            return $request->get('type');
        }

        if ($hasHeader) {
            return $request->header('x-mo-type');
        }

        return null;
    }

    /**
     * Resolve the route for the current request.
     *
     * @param Request $request
     * @return \Illuminate\Routing\Route|null
     */
    public function resolve(Request $request): ?Route
    {
        $routes = app('router')->getRoutes();

        // Start with default route
        $route = $routes->getByName('default');
        $route->bind($request);

        $type = $this->getTypeFromRequest($request);

        if ($type === 'request') {
            // Get modularRequest from cache (already fetched by FetchModularRequest pipe)
            $modularRequest = Cache::driver('array')->get('modularRequest');

            if (!$modularRequest) {
                Log::warning('ModularRouteResolver: modularRequest not in cache');

                return $route;
            }

            $routeType = $modularRequest->type;

            /** @var \Illuminate\Routing\Route $routeByName */
            $routeByName = $routes->getByName($routeType);

            if (!$routeByName) {
                Log::warning('ModularRouteResolver: Route not found', ['route_type' => $routeType]);

                return $route;
            }

            $route = $routeByName->bind($request);

            // Set modular_request parameter for controller access
            $route->setParameter('modular_request', $modularRequest);
        } elseif ($type === 'oauth') {
            $route = $routes->getByName('modular-connector.oauth');

            if (!$route) {
                Log::warning('ModularRouteResolver: OAuth route not found');

                return null;
            }

            $route = $route->bind($request);

            Log::debug('ModularRouteResolver: Resolved oauth route');
        } elseif ($type === 'lb') {
            $route = $routes->getByName('schedule.run');

            if (!$route) {
                Log::warning('ModularRouteResolver: Loopback route not found');

                return null;
            }

            $route = $route->bind($request);

            Log::debug('ModularRouteResolver: Resolved loopback route');
        } else {
            // Unknown type - should never reach here
            Log::warning('ModularRouteResolver: Unknown type', ['type' => $type]);

            return $route;
        }

        return $route;
    }
}
