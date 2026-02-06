<?php

namespace Modular\Connector\Http\Controllers;

use Modular\Connector\Facades\Server;
use Modular\Connector\Helper\OauthClient;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\ServerSetup;
use Modular\ConnectorDependencies\Carbon\Carbon;
use Modular\ConnectorDependencies\Illuminate\Http\Request;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Cache;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Response;
use Modular\SDK\Objects\SiteRequest;
use function Modular\ConnectorDependencies\abort;
use function Modular\ConnectorDependencies\data_get;

class AuthController
{
    /**
     * Get code from request (header XOR query, never both).
     *
     * @param \Illuminate\Http\Request $request
     * @return string|null
     */
    private function getCodeFromRequest($request): ?string
    {
        $hasQuery = $request->has('code');
        $hasHeader = $request->hasHeader('x-mo-code');

        if ($hasQuery) {
            return $request->get('code');
        }

        if ($hasHeader) {
            return $request->header('x-mo-code');
        }

        return null;
    }

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

        // code: XOR - query or header, never both (already validated in isDirectRequest)
        $code = $this->getCodeFromRequest($request);

        try {
            $token = $client->oauth->confirmAuthorizationCode($code);

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
     */
    public function getLogin(SiteRequest $modularRequest)
    {
        $body = $modularRequest->body;
        $userId = data_get($body, 'id');
        $useFallback = data_get($body, 'use_fallback', false);
        $redirectTo = data_get($body, 'redirect_to', 'index.php');

        $user = null;

        // Try to get the requested user by ID
        if (!empty($userId)) {
            $user = get_user_by('id', $userId);
        }

        // If user not found, check if fallback is allowed
        if (empty($user)) {
            if ($useFallback) {
                // Fallback allowed - get admin user and clear cached user
                Cache::driver('wordpress')->forget('user.login');

                $user = ServerSetup::getAdminUser();
            }
        }

        // If still no user, abort
        if (empty($user)) {
            abort(404, 'User not found or not authorized.');
        }

        // User found - cache it for future reference
        Cache::driver('wordpress')->forever('user.login', $user->ID);

        // Only allow relative paths (no protocol://)
        if (strpos($redirectTo, '://') !== false) {
            $redirectTo = 'index.php';
        }

        $cookies = ServerSetup::loginAs($user, true);

        return Response::redirectTo(admin_url($redirectTo))
            ->withCookies($cookies);
    }

    /**
     * @param SiteRequest $modularRequest
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getUsers(SiteRequest $modularRequest)
    {
        $users = ServerSetup::getAllAdminUsers();

        return Response::json($users);
    }
}
