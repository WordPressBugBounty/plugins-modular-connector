<?php

namespace Modular\ConnectorDependencies\Illuminate\Routing\Matching;

use Modular\ConnectorDependencies\Illuminate\Http\Request;
use Modular\ConnectorDependencies\Illuminate\Routing\Route;
class SchemeValidator implements ValidatorInterface
{
    /**
     * Validate a given rule against a route and request.
     *
     * @param  \Illuminate\Routing\Route  $route
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     */
    public function matches(Route $route, Request $request)
    {
        if ($route->httpOnly()) {
            return !$request->secure();
        } elseif ($route->secure()) {
            return $request->secure();
        }
        return \true;
    }
}
