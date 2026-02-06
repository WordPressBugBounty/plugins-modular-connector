<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Routing;

use Modular\ConnectorDependencies\Ares\Framework\Foundation\Contracts\RouteResolver;
use Modular\ConnectorDependencies\Illuminate\Http\Request;
use Modular\ConnectorDependencies\Illuminate\Routing\Route;
use Modular\ConnectorDependencies\Illuminate\Routing\Router as IlluminateRouter;
use Modular\ConnectorDependencies\Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Modular\ConnectorDependencies\Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
class Router extends IlluminateRouter
{
    /**
     * Find the route matching a given request.
     *
     * Uses RouteResolver contract for secure internal routing.
     *
     * @param Request $request
     * @return Route
     * @throws MethodNotAllowedHttpException
     * @throws NotFoundHttpException
     */
    protected function findRoute($request)
    {
        /** @var RouteResolver $routeResolver */
        $routeResolver = $this->container->make(RouteResolver::class);
        $route = $routeResolver->resolve($request);
        if (!$route) {
            throw new NotFoundHttpException('Route not found');
        }
        $this->current = $route;
        $route->setContainer($this->container);
        $this->container->instance(Route::class, $route);
        return $route;
    }
}
