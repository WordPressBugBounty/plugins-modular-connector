<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Routing;

use Modular\ConnectorDependencies\Illuminate\Http\Request;
use Modular\ConnectorDependencies\Illuminate\Routing\Route;
use Modular\ConnectorDependencies\Illuminate\Routing\Router as IlluminateRouter;
use Modular\ConnectorDependencies\Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
class Router extends IlluminateRouter
{
    /**
     * Find the route matching a given request.
     *
     * @param Request $request
     * @return Route
     * @throws MethodNotAllowedHttpException
     */
    protected function findRoute($request)
    {
        $this->current = $route = apply_filters('ares/routes/match', $this->routes->match($request), \true);
        $route->setContainer($this->container);
        $this->container->instance(Route::class, $route);
        return $route;
    }
}
