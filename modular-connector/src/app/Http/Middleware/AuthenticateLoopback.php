<?php

namespace Modular\Connector\Http\Middleware;

use Modular\Connector\Helper\OauthClient;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Auth\JWT;
use Modular\ConnectorDependencies\Illuminate\Http\Request;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use function Modular\ConnectorDependencies\abort;

/**
 * @deprecated This middleware is deprecated and will be removed in a future version.
 *
 * This middleware is kept for backward compatibility but is no longer used.
 * It will be removed once all routes are confirmed working with the new pipeline.
 *
 * @see \Ares\Framework\Foundation\Bootloader::runSecurityPipeline()
 * @see \Ares\Framework\Foundation\Http\Pipeline\AuthenticateJWT
 */
class AuthenticateLoopback
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param \Closure $next
     * @return mixed
     * @throws \Exception
     * @deprecated Use Bootloader security pipeline instead
     *
     * Authentication methods (mutually exclusive - one or the other):
     * - x-mo-authentication header with JWT (for loopback/oauth)
     * - sig query parameter with JWT (for type=request from API)
     *
     */
    public function handle($request, \Closure $next)
    {
        $hasAuthHeader = $request->hasHeader('x-mo-authentication');
        $hasSigParam = $request->has('sig');

        // Must have one authentication method
        if (!$hasAuthHeader && !$hasSigParam) {
            Log::debug('Authentication: No auth method provided', [
                'ip' => $request->ip(),
                'uri' => $request->fullUrl(),
            ]);

            abort(404);
        }

        // Cannot have both (mutual exclusivity - already validated in isDirectRequest)
        if ($hasAuthHeader && $hasSigParam) {
            Log::debug('Authentication: Both auth methods provided', [
                'ip' => $request->ip(),
            ]);

            abort(404);
        }

        // Get token from header or query
        $token = $hasAuthHeader
            ? $request->header('x-mo-authentication')
            : $request->get('sig');

        if (!$this->verifyAuthentication($request, $token)) {
            abort(404);
        }

        Log::debug('Authentication verified successfully', [
            'method' => $hasAuthHeader ? 'x-mo-authentication' : 'sig',
        ]);

        return $next($request);
    }

    /**
     * Verify JWT authentication with client_secret.
     *
     * @param Request $request
     * @param string $token JWT token from header
     * @return bool
     */
    private function verifyAuthentication(Request $request, string $token): bool
    {
        if (empty($token)) {
            Log::debug('x-mo-authentication: Token is empty');
            return false;
        }

        // Get client_secret from OAuth client
        $clientSecret = OauthClient::getClient()->getClientSecret();

        if (empty($clientSecret)) {
            Log::debug('x-mo-authentication: client_secret is empty - site not connected');

            return false;
        }

        // Get request data based on route for validation
        $requestData = $this->getRequestData($request);

        if ($requestData === null) {
            Log::debug('x-mo-authentication: Unable to get request data');

            return false;
        }

        // Verify JWT signature and claims
        $jwtPayload = $this->verifyJwt($token, $clientSecret);

        if ($jwtPayload === null) {
            Log::debug('x-mo-authentication: JWT verification failed');

            return false;
        }

        // Verify client_id matches (site-specific token binding)
        $siteClientId = OauthClient::getClient()->getClientId();
        $jwtClientId = $jwtPayload->client_id ?? null;

        if (empty($siteClientId) || empty($jwtClientId)) {
            Log::debug('x-mo-authentication: client_id missing', [
                'site_client_id' => $siteClientId ? 'present' : 'missing',
                'jwt_client_id' => $jwtClientId ? 'present' : 'missing',
            ]);

            return false;
        }

        if (!hash_equals($siteClientId, $jwtClientId)) {
            Log::debug('x-mo-authentication: client_id mismatch');

            return false;
        }

        Log::debug('x-mo-authentication: client_id verified');

        // For loopback requests, verify lbn (loopback nonce) matches
        if (isset($requestData->type) && $requestData->type === 'loopback') {
            $queryLbn = $requestData->lbn ?? null;
            $jwtLbn = $jwtPayload->lbn ?? null;

            if (empty($queryLbn) || empty($jwtLbn)) {
                Log::debug('x-mo-authentication: Loopback nonce missing', [
                    'query_lbn' => $queryLbn ? 'present' : 'missing',
                    'jwt_lbn' => $jwtLbn ? 'present' : 'missing',
                ]);
                return false;
            }

            if (!hash_equals($jwtLbn, $queryLbn)) {
                Log::debug('x-mo-authentication: Loopback nonce mismatch');

                return false;
            }

            Log::debug('x-mo-authentication: Loopback nonce verified');
        }

        return true;
    }

    /**
     * Get request data based on current route.
     *
     * @param Request $request
     * @return object|array|null
     */
    private function getRequestData(Request $request)
    {
        $routeName = $request->route()->getName();

        // Special handling for OAuth route
        if ($routeName === 'modular-connector.oauth') {
            // code: XOR - query or header, never both (already validated in isDirectRequest)
            $code = $request->has('code')
                ? $request->get('code')
                : $request->header('x-mo-code');

            return (object)[
                'code' => $code,
                'state' => $request->get('state'),
            ];
        }

        // Special handling for schedule.run route (loopback)
        if ($routeName === 'schedule.run') {
            return (object)[
                'type' => 'loopback',
                'lbn' => $request->get('lbn'), // Loopback nonce from query string
            ];
        }

        // For other routes, get modularRequest from route parameter
        $modularRequest = $request->route()->parameter('modular_request');

        if (!$modularRequest) {
            Log::debug('x-mo-authentication: modularRequest not found', [
                'route' => $routeName,
            ]);

            return null;
        }

        // Validate modularRequest structure
        $requiredKeys = [
            'id',
            'request_id',
            'type',
            'body',
            'site_id',
            'created_at',
            'updated_at',
            'deleted_at',
            'status',
            'expired_at',
        ];

        /**
         * @var \Modular\SDK\Objects\SiteRequest $modularRequest
         */
        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $modularRequest->attributesToArray())) {
                Log::debug('x-mo-authentication: modularRequest missing required key', [
                    'missing_key' => $key,
                    'route' => $routeName,
                ]);

                return null;
            }
        }

        return $modularRequest;
    }

    /**
     * Verify JWT signature, structure, and temporal claims.
     *
     * @param string $token
     * @param string $clientSecret
     * @return object|null Returns payload on success, null on failure
     */
    private function verifyJwt(string $token, string $clientSecret): ?object
    {
        // Remove Bearer prefix if present
        $token = str_replace('Bearer ', '', $token);
        $jwtParts = explode('.', $token);

        if (count($jwtParts) !== 3) {
            Log::debug('x-mo-authentication: Invalid JWT structure - expected 3 parts');
            return null;
        }

        [$base64UrlHeader, $base64UrlPayload, $base64UrlSignature] = $jwtParts;

        // Decode header and payload with strict JSON parsing
        try {
            $headerJson = JWT::base64UrlDecode($base64UrlHeader);
            $payloadJson = JWT::base64UrlDecode($base64UrlPayload);

            $header = json_decode($headerJson, false, 512, JSON_THROW_ON_ERROR);
            $payload = json_decode($payloadJson, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            Log::debug('x-mo-authentication: JSON decode failed', ['error' => $e->getMessage()]);

            return null;
        }

        if ($header->alg !== 'HS256') {
            Log::debug('x-mo-authentication: Invalid algorithm', ['alg' => $header->alg]);

            return null;
        }

        // Verify signature using timing-safe comparison
        $signatureInput = "{$base64UrlHeader}.{$base64UrlPayload}";
        $expectedSignature = hash_hmac('sha256', $signatureInput, $clientSecret, true);
        $receivedSignature = JWT::base64UrlDecode($base64UrlSignature);

        if (!hash_equals($expectedSignature, $receivedSignature)) {
            Log::debug('x-mo-authentication: JWT signature mismatch');
            return null;
        }

        // Verify temporal claims
        $currentTime = time();

        // Check expiration (exp)
        if (isset($payload->exp) && $currentTime > $payload->exp) {
            Log::debug('x-mo-authentication: JWT expired', [
                'exp' => $payload->exp,
                'now' => $currentTime,
                'expired_ago' => $currentTime - $payload->exp,
            ]);
            return null;
        }

        // Check not before (iat - issued at)
        if (isset($payload->iat) && $currentTime < $payload->iat) {
            Log::debug('x-mo-authentication: JWT not yet valid', [
                'iat' => $payload->iat,
                'now' => $currentTime,
            ]);
            return null;
        }

        Log::debug('x-mo-authentication: JWT verified successfully', [
            'client_id' => $payload->client_id ?? 'not set',
            'type' => $payload->type ?? 'not set',
        ]);

        return $payload;
    }
}
