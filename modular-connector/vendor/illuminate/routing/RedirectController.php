<?php

namespace Modular\ConnectorDependencies\Illuminate\Routing;

use Modular\ConnectorDependencies\Illuminate\Http\RedirectResponse;
use Modular\ConnectorDependencies\Illuminate\Http\Request;
use Modular\ConnectorDependencies\Illuminate\Support\Str;
class RedirectController extends Controller
{
    /**
     * Invoke the controller method.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Illuminate\Routing\UrlGenerator  $url
     * @return \Illuminate\Http\RedirectResponse
     */
    public function __invoke(Request $request, UrlGenerator $url)
    {
        $parameters = \Modular\ConnectorDependencies\collect($request->route()->parameters());
        $status = $parameters->get('status');
        $destination = $parameters->get('destination');
        $parameters->forget('status')->forget('destination');
        $route = (new Route('GET', $destination, ['as' => 'laravel_route_redirect_destination']))->bind($request);
        $parameters = $parameters->only($route->getCompiled()->getPathVariables())->toArray();
        $url = $url->toRoute($route, $parameters, \false);
        if (!Str::startsWith($destination, '/') && Str::startsWith($url, '/')) {
            $url = Str::after($url, '/');
        }
        return new RedirectResponse($url, $status);
    }
}
