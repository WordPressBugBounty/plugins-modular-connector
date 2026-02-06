<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Contracts;

use Modular\ConnectorDependencies\Illuminate\Http\Request;
/**
 * Route resolver contract for Modular Connector routing.
 *
 * This contract defines how routes are resolved for Modular requests.
 * The plugin provides the implementation that maps request types to Laravel routes.
 *
 * Uses dependency injection for secure internal routing.
 */
interface RouteResolver
{
    /**
     * Resolve the route for the current request.
     *
     * @param Request $request The current request
     * @return \Illuminate\Routing\Route|null The resolved route or null if not found
     */
    public function resolve(Request $request): ?\Modular\ConnectorDependencies\Illuminate\Routing\Route;
}
