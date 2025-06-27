<?php

namespace Modular\Connector\Providers;

use Modular\Connector\Helper\OauthClient;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Http\HttpUtils;
use Modular\ConnectorDependencies\GuzzleHttp\Exception\ClientException;
use Modular\ConnectorDependencies\GuzzleHttp\Exception\ServerException;
use Modular\ConnectorDependencies\Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Cache;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Route;
use Modular\ConnectorDependencies\Symfony\Component\HttpKernel\Exception\HttpException;
use function Modular\ConnectorDependencies\app;
use function Modular\ConnectorDependencies\base_path;
use function Modular\ConnectorDependencies\request;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * @param string $requestId
     * @return \Modular\SDK\Objects\BaseObject|null
     * @throws \ErrorException
     */
    private function getModularRequest(string $requestId)
    {
        $client = OauthClient::getClient();
        $client->validateOrRenewAccessToken();

        try {
            return $client->wordpress->handleRequest($requestId);
        } catch (ClientException|ServerException $e) {
            throw new HttpException($e->getResponse()->getStatusCode(), $e->getMessage());
        } catch (\Throwable $e) {
            // Silence is golden
            return null;
        }
    }

    /**
     * @param $route
     * @param $removeQuery
     * @return \Illuminate\Routing\Route|mixed
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function bindOldRoutes($route, $removeQuery = false)
    {
        if (!HttpUtils::isDirectRequest()) {
            return $route;
        }

        $request = request();

        $routes = app('router')->getRoutes();

        if ($request->get('type') === 'request') {
            $modularRequest = Cache::driver('array')->get('modularRequest') ?: $this->getModularRequest($request->get('mrid'));

            if (!$modularRequest) {
                return $route;
            }

            if (!$removeQuery && !Cache::driver('array')->has('modularRequest')) {
                Cache::driver('array')->set('modularRequest', $modularRequest);
            } elseif ($removeQuery) {
                Cache::driver('array')->forget('modularRequest');
            }

            $type = $modularRequest->type;

            /**
             * @var \Illuminate\Routing\Route $route
             */
            $routeByName = $routes->getByName($type);

            if (!$routeByName) {
                return $route;
            }

            $route = $routeByName;

            if ($removeQuery) {
                $request->query->remove('origin');
                $request->query->remove('type');
                $request->query->remove('mrid');
            }

            $route = $route->bind($request);

            if ($removeQuery) {
                $params = $route->parameterNames();

                if (in_array('modular_request', $params)) {
                    $route->setParameter('modular_request', $modularRequest);
                }
            }
        }

        if ($request->get('type') === 'oauth') {
            /**
             * @var \Illuminate\Routing\Route $route
             */
            $route = $routes->getByName('modular-connector.oauth');

            if ($removeQuery) {
                $request->query->remove('origin');
                $request->query->remove('type');
            }

            $route = $route->bind($request);
        }

        if ($request->get('type') === 'lb') {
            /**
             * @var \Illuminate\Routing\Route $route
             */
            $route = $routes->getByName('schedule.run');

            if ($removeQuery) {
                $request->query->remove('origin');
                $request->query->remove('type');
            }

            $route = $route->bind($request);
        }

        if ($request->hasHeader('authorization')) {
            $authorization = $request->header('Authorization');

            Cache::driver('wordpress')->forever('header.authorization', $authorization);
        } elseif (Cache::driver('wordpress')->has('header.authorization')) {
            Cache::driver('wordpress')->forget('header.authorization');
        }

        return $route;
    }

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     *
     * @return void
     */
    public function boot()
    {
        $this->routes(function () {
            Route::prefix('/api/modular-connector')
                ->group(base_path('routes/api.php'));
        });

        add_filter('ares/routes/match', [$this, 'bindOldRoutes'], 10, 2);
    }
}
