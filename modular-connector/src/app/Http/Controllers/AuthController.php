<?php

namespace Modular\Connector\Http\Controllers;

use Modular\Connector\Facades\Server;
use Modular\Connector\Helper\OauthClient;
use Modular\ConnectorDependencies\Carbon\Carbon;
use Modular\ConnectorDependencies\Illuminate\Http\Request;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Response;
use Modular\SDK\Objects\SiteRequest;
use function Modular\ConnectorDependencies\data_get;

class AuthController
{
    /**
     * Confirm OAuth from Modular
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     * @throws \ErrorException
     */
    public function postConfirmOauth(Request $request)
    {
        $client = OauthClient::getClient();

        try {
            $token = $client->oauth->confirmAuthorizationCode($request->get('code'));

            $client->setAccessToken($token->access_token)
                ->setRefreshToken($token->refresh_token)
                ->setExpiresIn($token->expires_in)
                ->setConnectedAt(Carbon::now())
                ->save();
        } catch (\Throwable $e) {
            $client->setAccessToken('')
                ->setRefreshToken('')
                ->setExpiresIn(0)
                ->setConnectedAt(null)
                ->save();

            Log::error(sprintf('%s on line %d', $e->getMessage(), $e->getLine()), [
                'code' => $e->getCode(),
                'trace' => $e->getTraceAsString(),
            ]);

            return Response::json([
                'success' => 'KO',
            ]);
        }

        return Response::json([
            'success' => 'OK',
            'version' => Server::connectorVersion(),
        ]);
    }

    /**
     * @param SiteRequest $modularRequest
     * @return \Illuminate\Http\RedirectResponse
     * @throws \Exception
     */
    public function getLogin(SiteRequest $modularRequest)
    {
        $user = data_get($modularRequest->body, 'user', Server::getAdminUser());

        if (empty($user)) {
            // TODO Make a custom exception
            throw new \Exception('No admin user detected.');
        }

        $cookies = Server::login($user, true);

        return Response::redirectTo(admin_url('index.php'))
            ->withCookies($cookies);
    }
}
