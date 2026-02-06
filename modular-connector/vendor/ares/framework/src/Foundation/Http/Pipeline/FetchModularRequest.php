<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Http\Pipeline;

use Closure;
use Modular\ConnectorDependencies\Illuminate\Http\Request;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Cache;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use Modular\ConnectorDependencies\Illuminate\Support\Str;
use Modular\SDK\ModularClient;
use Modular\SDK\ModularClientInterface;
/**
 * Fetch and validate Modular request from API.
 *
 * This pipe:
 * - Only executes for type=request (not oauth or lb)
 * - Validates mrid is valid UUID (defense in depth - already validated in entry point)
 * - Fetches request details from Modular API via ModularClient
 * - Validates modularRequest structure has all required fields:
 *   (id, request_id, type, body, created_at, updated_at, status, expired_at)
 * - Stores request in array cache for later use (RouteServiceProvider, controllers)
 * - Aborts with 404 if mrid invalid, request not found, or structure invalid
 *
 * Uses ModularClient singleton to fetch from Modular API,
 * maintaining separation between framework and plugin code.
 */
class FetchModularRequest
{
    /**
     * Modular SDK client instance.
     */
    private ModularClient $client;
    /**
     * Constructor.
     *
     * @param ModularClient $client
     */
    public function __construct(ModularClientInterface $client)
    {
        $this->client = $client;
    }
    /**
     * Handle fetching Modular request.
     *
     * @param Request $request
     * @param Closure $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Get type from header or query
        $type = $request->has('type') ? $request->get('type') : $request->header('x-mo-type');
        // Only validate for type=request (oauth and lb don't need modular_request)
        if ($type === 'lb' || $type === 'oauth') {
            Log::debug('FetchModularRequest: Skipping (not type=request)', ['type' => $type]);
            return $next($request);
        }
        // Get mrid from header or query
        $mrid = $request->has('mrid') ? $request->get('mrid') : $request->header('x-mo-mrid');
        // Defense in depth: validate mrid is valid UUID
        if (!$mrid || !Str::isUuid($mrid)) {
            Log::warning('FetchModularRequest: Invalid mrid', ['mrid' => $mrid, 'is_uuid' => $mrid ? Str::isUuid($mrid) : \false]);
            \Modular\ConnectorDependencies\abort(404);
        }
        Log::debug('FetchModularRequest: Fetching from Modular API', ['mrid' => $mrid]);
        // Fetch from Modular API via SDK client
        try {
            $this->client->validateOrRenewAccessToken();
            $modularRequest = $this->client->wordpress->handleRequest($mrid);
        } catch (\Throwable $e) {
            Log::warning('FetchModularRequest: Failed to fetch request', ['mrid' => $mrid, 'error' => $e->getMessage()]);
            \Modular\ConnectorDependencies\abort(404);
        }
        if (!$modularRequest) {
            Log::warning('FetchModularRequest: Request not found', ['mrid' => $mrid]);
            \Modular\ConnectorDependencies\abort(404);
        }
        // Validate modularRequest structure (similar to old AuthenticateLoopback::getRequestData)
        if (!$this->validateModularRequestStructure($modularRequest)) {
            Log::warning('FetchModularRequest: Invalid request structure', ['mrid' => $mrid]);
            \Modular\ConnectorDependencies\abort(404);
        }
        // Cache for later use (RouteServiceProvider, controllers)
        Cache::driver('array')->set('modularRequest', $modularRequest);
        Log::debug('FetchModularRequest: Request fetched and cached', ['mrid' => $mrid, 'type' => $modularRequest->type ?? 'unknown']);
        return $next($request);
    }
    /**
     * Validate modularRequest structure.
     *
     * This method replicates the validation logic from the old AuthenticateLoopback
     * middleware's getRequestData() method, ensuring that the modularRequest object
     * returned by the API has all required fields.
     *
     * @param object $modularRequest SiteRequest object from Modular API
     * @return bool
     */
    private function validateModularRequestStructure(object $modularRequest): bool
    {
        $requiredKeys = ['id', 'request_id', 'type', 'body', 'created_at', 'updated_at', 'status', 'expired_at'];
        /**
         * @var \Modular\SDK\Objects\SiteRequest $modularRequest
         */
        $attributes = $modularRequest->attributesToArray();
        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $attributes)) {
                Log::warning('FetchModularRequest: Missing required field in modularRequest', ['missing_key' => $key, 'available_keys' => array_keys($attributes)]);
                return \false;
            }
        }
        Log::debug('FetchModularRequest: Request structure validated', ['type' => $modularRequest->type, 'status' => $modularRequest->status]);
        return \true;
    }
}
