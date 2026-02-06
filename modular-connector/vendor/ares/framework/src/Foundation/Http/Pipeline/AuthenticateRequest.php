<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Http\Pipeline;

use Modular\ConnectorDependencies\Ares\Framework\Foundation\Auth\JWT;
use Closure;
use Modular\ConnectorDependencies\Illuminate\Http\Request;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use Modular\SDK\ModularClient;
use Modular\SDK\ModularClientInterface;
/**
 * Authenticate request using JWT token.
 *
 * This pipe validates:
 * - JWT token is present (x-mo-authentication header or sig query parameter)
 * - JWT signature is valid (HMAC-SHA256 or HMAC-SHA512 with client_secret)
 * - JWT claims are valid (exp, iat)
 * - client_id matches
 * - For loopback (type=lb): lbn (loopback nonce) matches JWT payload
 *
 * Uses ModularClient singleton to get credentials from the SDK,
 * maintaining separation between framework and plugin code.
 */
class AuthenticateRequest
{
    /**
     * Modular SDK client instance.
     */
    private ModularClient $client;
    /**
     * Constructor.
     *
     * @param \Modular\SDK\ModularClientInterface $client
     */
    public function __construct(ModularClientInterface $client)
    {
        $this->client = $client;
    }
    /**
     * Handle the JWT authentication.
     *
     * @param Request $request
     * @param Closure $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $hasAuthHeader = $request->hasHeader('x-mo-authentication');
        $hasSigParam = $request->has('sig');
        // Must have one authentication method
        if (!$hasAuthHeader && !$hasSigParam) {
            Log::debug('Authentication: No auth method provided', ['ip' => $request->ip(), 'uri' => $request->fullUrl()]);
            \Modular\ConnectorDependencies\abort(404);
        }
        // Cannot have both (mutual exclusivity - already validated in isDirectRequest)
        if ($hasAuthHeader && $hasSigParam) {
            Log::debug('Authentication: Both auth methods provided', ['ip' => $request->ip()]);
            \Modular\ConnectorDependencies\abort(404);
        }
        // Get token from header or query
        $token = $hasAuthHeader ? $request->header('x-mo-authentication') : $request->get('sig');
        if (!$this->verifyAuthentication($request, $token)) {
            \Modular\ConnectorDependencies\abort(404);
        }
        Log::debug('Authentication verified successfully', ['method' => $hasAuthHeader ? 'x-mo-authentication' : 'sig']);
        return $next($request);
    }
    /**
     * Verify JWT authentication with client_secret.
     *
     * @param Request $request
     * @param string $token JWT token from header or query
     * @return bool
     */
    private function verifyAuthentication(Request $request, string $token): bool
    {
        if (empty($token)) {
            Log::debug('Authentication: Token is empty');
            return \false;
        }
        // Get client_secret from Modular SDK client
        $clientSecret = $this->client->getClientSecret();
        if (empty($clientSecret)) {
            Log::debug('Authentication: client_secret is empty - site not connected');
            return \false;
        }
        // Verify JWT signature and claims
        $jwtPayload = $this->verifyJwt($token, $clientSecret);
        if ($jwtPayload === null) {
            Log::debug('Authentication: JWT verification failed');
            return \false;
        }
        // Verify client_id matches (site-specific token binding)
        $siteClientId = $this->client->getClientId();
        $jwtClientId = $jwtPayload->client_id ?? null;
        if (empty($siteClientId) || empty($jwtClientId)) {
            Log::debug('Authentication: client_id missing', ['site_client_id' => $siteClientId ? 'present' : 'missing', 'jwt_client_id' => $jwtClientId ? 'present' : 'missing']);
            return \false;
        }
        if (!hash_equals($siteClientId, $jwtClientId)) {
            Log::debug('Authentication: client_id mismatch');
            return \false;
        }
        Log::debug('Authentication: client_id verified');
        // Validate request parameters based on type (similar to old getRequestData() method)
        $type = $request->has('type') ? $request->get('type') : $request->header('x-mo-type');
        if (!$this->validateRequestData($request, $type, $jwtPayload)) {
            return \false;
        }
        return \true;
    }
    /**
     * Validate request parameters based on type.
     *
     * Most parameter validation is done in HttpUtils::isDirectRequest() BEFORE this pipe.
     * This method only validates what requires JWT payload access:
     *
     * @param Request $request
     * @param string $type Request type (oauth, lb, request) - guaranteed to exist
     * @param object $jwtPayload JWT payload for loopback nonce verification
     * @return bool
     */
    private function validateRequestData(Request $request, string $type, object $jwtPayload): bool
    {
        if ($type === 'lb') {
            // Loopback requires: lbn (loopback nonce) matching JWT
            $queryLbn = $request->get('lbn');
            $jwtLbn = $jwtPayload->lbn ?? null;
            if (empty($queryLbn) || empty($jwtLbn)) {
                Log::debug('Authentication: Loopback nonce missing', ['query_lbn' => $queryLbn ? 'present' : 'missing', 'jwt_lbn' => $jwtLbn ? 'present' : 'missing']);
                return \false;
            }
            if (!hash_equals($jwtLbn, $queryLbn)) {
                Log::debug('Authentication: Loopback nonce mismatch');
                return \false;
            }
        }
        return \true;
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
        // Whitelist of allowed HMAC algorithms
        $allowedAlgorithms = ['HS256' => 'sha256', 'HS512' => 'sha512'];
        // Remove Bearer prefix if present
        $token = str_replace('Bearer ', '', $token);
        $jwtParts = explode('.', $token);
        if (count($jwtParts) !== 3) {
            Log::debug('Authentication: Invalid JWT structure - expected 3 parts');
            return null;
        }
        [$base64UrlHeader, $base64UrlPayload, $base64UrlSignature] = $jwtParts;
        // Decode header and payload with strict JSON parsing
        try {
            $headerJson = JWT::base64UrlDecode($base64UrlHeader);
            $payloadJson = JWT::base64UrlDecode($base64UrlPayload);
            $header = json_decode($headerJson, \false, 512, \JSON_THROW_ON_ERROR);
            $payload = json_decode($payloadJson, \false, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            Log::debug('Authentication: JSON decode failed', ['error' => $e->getMessage()]);
            return null;
        }
        // Validate algorithm is in whitelist
        $algorithm = $header->alg ?? null;
        if (!isset($allowedAlgorithms[$algorithm])) {
            Log::debug('Authentication: Invalid or unsupported algorithm', ['alg' => $algorithm, 'allowed' => array_keys($allowedAlgorithms)]);
            return null;
        }
        // Get hash algorithm for HMAC
        $hashAlgorithm = $allowedAlgorithms[$algorithm];
        // Verify signature using timing-safe comparison
        $signatureInput = "{$base64UrlHeader}.{$base64UrlPayload}";
        $expectedSignature = hash_hmac($hashAlgorithm, $signatureInput, $clientSecret, \true);
        $receivedSignature = JWT::base64UrlDecode($base64UrlSignature);
        if (!hash_equals($expectedSignature, $receivedSignature)) {
            Log::debug('Authentication: JWT signature mismatch', ['algorithm' => $algorithm]);
            return null;
        }
        // Verify temporal claims
        $currentTime = time();
        // Check expiration (exp)
        if (isset($payload->exp) && $currentTime > $payload->exp) {
            Log::debug('Authentication: JWT expired', ['exp' => $payload->exp, 'now' => $currentTime, 'expired_ago' => $currentTime - $payload->exp]);
            return null;
        }
        // Check not before (iat - issued at)
        if (isset($payload->iat) && $currentTime < $payload->iat) {
            Log::debug('Authentication: JWT not yet valid', ['iat' => $payload->iat, 'now' => $currentTime]);
            return null;
        }
        return $payload;
    }
}
