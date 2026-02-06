<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Http\Pipeline;

use Modular\ConnectorDependencies\Ares\Framework\Foundation\Contracts\RouteResolver;
use Closure;
use Modular\ConnectorDependencies\Illuminate\Http\Request;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
/**
 * Validate that a route exists for the request.
 *
 * This pipe validates:
 * - Route exists and is not the default route
 */
class ValidateRoute
{
    /**
     * Route resolver instance.
     */
    private RouteResolver $routeResolver;
    /**
     * Constructor.
     *
     * @param RouteResolver $routeResolver
     */
    public function __construct(RouteResolver $routeResolver)
    {
        $this->routeResolver = $routeResolver;
    }
    /**
     * Handle the route validation.
     *
     * IMPORTANT: At this point, we already validated that this is a direct request
     * for Modular Connector (isDirectRequest() = true, JWT valid, etc.).
     * Therefore, a route MUST exist. If not, it's a 404 from our application.
     *
     * @param Request $request
     * @param Closure $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Resolve route using internal resolver
        $route = $this->routeResolver->resolve($request);
        // Route must exist and not be 'default' (invalid)
        if (!$route || $route->getName() === 'default') {
            Log::warning('ValidateRoute: No valid route found for Modular request', ['ip' => $request->ip(), 'uri' => $request->fullUrl(), 'route' => $route ? $route->getName() : 'null']);
            \Modular\ConnectorDependencies\abort(404);
        }
        return $next($request);
    }
}
