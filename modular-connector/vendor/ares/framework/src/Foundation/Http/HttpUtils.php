<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Http;

use Modular\ConnectorDependencies\Illuminate\Http\Request;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Cache;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use Modular\ConnectorDependencies\Illuminate\Support\Str;
class HttpUtils
{
    private const ALLOWED_DIRECT_REQUEST_TYPES = ['lb', 'request', 'oauth'];
    /**
     * Get memory limit in MB.
     *
     * @return int
     */
    public static function currentMemoryLimit(): int
    {
        if (function_exists('ini_get')) {
            $memoryLimit = ini_get('memory_limit');
        } else {
            $memoryLimit = '256M';
        }
        if (!$memoryLimit) {
            $memoryLimit = '256M';
        }
        return $memoryLimit !== -1 ? wp_convert_hr_to_bytes($memoryLimit) : $memoryLimit;
    }
    /**
     * @param bool $inMegabytes
     * @return int
     */
    public static function maxMemoryLimit(bool $inMegabytes = \false): int
    {
        $currentMemoryLimit = HttpUtils::currentMemoryLimit();
        $maxMemoryLimit = defined('WP_MAX_MEMORY_LIMIT') ? \WP_MAX_MEMORY_LIMIT : '512M';
        if (!$maxMemoryLimit || intval($maxMemoryLimit) === -1) {
            // Unlimited, set to 3GB.
            $maxMemoryLimit = '3200M';
        }
        $maxMemoryLimit = wp_convert_hr_to_bytes($maxMemoryLimit);
        if ($maxMemoryLimit > $currentMemoryLimit) {
            $currentMemoryLimit = $maxMemoryLimit;
        }
        return $inMegabytes ? $currentMemoryLimit / 1024 / 1024 : $currentMemoryLimit;
    }
    /**
     * @return bool
     */
    public static function isMultisite()
    {
        return is_multisite();
    }
    /**
     * @return bool
     */
    public static function isMuPlugin()
    {
        return \Modular\ConnectorDependencies\data_get($GLOBALS, 'modular_is_mu_plugin', \false);
    }
    /**
     * @return void
     */
    public static function restartQueue(int $timestamp)
    {
        Cache::forever('illuminate:queue:restart', $timestamp);
        \Modular\ConnectorDependencies\app('log')->info('Broadcasting queue restart signal.');
    }
    /**
     * Check if this is a valid cron request.
     * Must be from wp-cron.php AND have DOING_CRON defined.
     *
     * @return bool
     */
    public static function isCron(): bool
    {
        if (!defined('DOING_CRON') || !\DOING_CRON) {
            return \false;
        }
        $request = \Modular\ConnectorDependencies\app('request');
        $scriptName = $request->server('SCRIPT_NAME', $request->server('PHP_SELF', ''));
        return Str::endsWith($scriptName, 'wp-cron.php');
    }
    /**
     * @return bool
     */
    public static function isDirectRequest(): bool
    {
        $request = \Modular\ConnectorDependencies\app('request');
        if (!self::isValidEntryPoint()) {
            return \false;
        }
        if (!self::validateHttpMethod($request)) {
            return \false;
        }
        if ($request->get('origin') !== 'mo') {
            return \false;
        }
        $typeData = self::extractAndValidateType($request);
        if (!$typeData) {
            return \false;
        }
        if (!self::validateQueryParameters($request)) {
            return \false;
        }
        $authData = self::validateAuthentication($request, $typeData['type']);
        if (!$authData) {
            return \false;
        }
        if (!self::validateTypeSpecificRequirements($request, $typeData)) {
            return \false;
        }
        return self::validateAllowedParameters($request, $typeData, $authData);
    }
    /**
     * Validate that the request comes from wp-load.php only.
     *
     * Handles both standard installations and WordPress in subdirectories.
     *
     * @return bool
     */
    private static function isValidEntryPoint(): bool
    {
        $request = \Modular\ConnectorDependencies\app('request');
        $scriptName = $request->server('SCRIPT_NAME', $request->server('PHP_SELF', ''));
        // Standard case: script name ends with wp-load.php
        if (Str::endsWith($scriptName, 'wp-load.php')) {
            return \true;
        }
        // Subdirectory case: index.php with path ending in wp-load.php
        if ($scriptName === '/index.php' && Str::endsWith($request->path(), 'wp-load.php')) {
            return \true;
        }
        return \false;
    }
    /**
     * Validate User-Agent header matches expected pattern.
     *
     * @return bool
     */
    private static function isValidUserAgent(): bool
    {
        $request = \Modular\ConnectorDependencies\app('request');
        $userAgent = $request->userAgent() ?? '';
        if (!Str::is('ModularConnector/* (Linux)', $userAgent)) {
            \Modular\ConnectorDependencies\app('log')->debug('isDirectRequest: Rejected - invalid User-Agent', ['user_agent' => $userAgent, 'expected_pattern' => 'ModularConnector/* (Linux)']);
            return \false;
        }
        return \true;
    }
    /**
     * @param \Illuminate\Http\Request $request
     * @return bool
     */
    private static function validateHttpMethod(Request $request): bool
    {
        if ($request->getRealMethod() !== 'GET') {
            \Modular\ConnectorDependencies\app('log')->debug('isDirectRequest: Rejected - invalid HTTP method', ['real_method' => $request->getRealMethod(), 'spoofed_method' => $request->method()]);
            return \false;
        }
        return \true;
    }
    /**
     * Extract a value that can be in query OR header, but not both.
     *
     * @param \Illuminate\Http\Request $request
     * @param string $queryParam
     * @param string $headerName
     * @return array ['value' => mixed, 'inQuery' => bool, 'error' => bool]
     */
    private static function extractExclusiveValue($request, string $queryParam, string $headerName): array
    {
        $hasQuery = $request->has($queryParam);
        $hasHeader = $request->hasHeader($headerName);
        if ($hasQuery && $hasHeader) {
            return ['value' => null, 'inQuery' => \false, 'error' => \true];
        }
        if (!$hasQuery && !$hasHeader) {
            return ['value' => null, 'inQuery' => \false, 'error' => \false];
        }
        return ['value' => $hasQuery ? $request->get($queryParam) : $request->header($headerName), 'inQuery' => $hasQuery, 'error' => \false];
    }
    /**
     * @param \Illuminate\Http\Request $request
     * @return array|false Returns ['type' => string, 'hasTypeQuery' => bool] or false
     */
    private static function extractAndValidateType($request)
    {
        $result = self::extractExclusiveValue($request, 'type', 'x-mo-type');
        if ($result['error']) {
            \Modular\ConnectorDependencies\app('log')->debug('isDirectRequest: Rejected - type in both query and header');
            return \false;
        }
        if (empty($result['value'])) {
            return \false;
        }
        if (!in_array($result['value'], self::ALLOWED_DIRECT_REQUEST_TYPES, \true)) {
            return \false;
        }
        return ['type' => $result['value'], 'hasTypeQuery' => $result['inQuery']];
    }
    /**
     * @param \Illuminate\Http\Request $request
     * @return bool
     */
    private static function validateQueryParameters($request): bool
    {
        $allParams = array_keys($request->query->all());
        foreach ($allParams as $param) {
            $value = $request->get($param);
            if (!is_string($value)) {
                \Modular\ConnectorDependencies\app('log')->debug('isDirectRequest: Rejected - parameter is not a string', ['param' => $param, 'type' => gettype($value)]);
                return \false;
            }
        }
        return \true;
    }
    /**
     * @param \Illuminate\Http\Request $request
     * @param string $type
     * @return array|false Returns ['hasAuthHeader' => bool, 'hasSigParam' => bool] or false
     */
    private static function validateAuthentication($request, string $type)
    {
        $hasAuthHeader = $request->hasHeader('x-mo-authentication');
        $hasSigParam = $request->has('sig');
        if ($hasAuthHeader && $hasSigParam) {
            \Modular\ConnectorDependencies\app('log')->debug('isDirectRequest: Rejected - cannot use both x-mo-authentication and sig');
            return \false;
        }
        if (!$hasSigParam && !self::isValidUserAgent()) {
            return \false;
        }
        if ($type === 'request') {
            if (!$hasAuthHeader && !$hasSigParam) {
                \Modular\ConnectorDependencies\app('log')->debug('isDirectRequest: Rejected - authentication required (header or sig)', ['type' => $type]);
                return \false;
            }
        } else if (!$hasAuthHeader) {
            \Modular\ConnectorDependencies\app('log')->debug('isDirectRequest: Rejected - x-mo-authentication header required', ['type' => $type]);
            return \false;
        }
        return ['hasAuthHeader' => $hasAuthHeader, 'hasSigParam' => $hasSigParam];
    }
    /**
     * @param \Illuminate\Http\Request $request
     * @param array $typeData
     * @return bool
     */
    private static function validateTypeSpecificRequirements($request, array $typeData): bool
    {
        $type = $typeData['type'];
        if ($type === 'request') {
            return self::validateMridRequirement($request);
        }
        if ($type === 'oauth') {
            return self::validateCodeRequirement($request) && self::validateStateRequirement($request);
        }
        if ($type === 'lb') {
            return self::validateLoopbackNumber($request);
        }
        return \false;
    }
    /**
     * @param \Illuminate\Http\Request $request
     * @return bool
     */
    private static function validateMridRequirement($request): bool
    {
        $result = self::extractExclusiveValue($request, 'mrid', 'x-mo-mrid');
        if ($result['error']) {
            \Modular\ConnectorDependencies\app('log')->debug('isDirectRequest: Rejected - mrid in both query and header');
            return \false;
        }
        if (empty($result['value'])) {
            \Modular\ConnectorDependencies\app('log')->debug('isDirectRequest: Rejected - mrid required for type=request');
            return \false;
        }
        if (!Str::isUuid($result['value'])) {
            \Modular\ConnectorDependencies\app('log')->debug('isDirectRequest: Rejected - mrid must be a valid UUID', ['mrid' => $result['value']]);
            return \false;
        }
        return \true;
    }
    /**
     * @param \Illuminate\Http\Request $request
     * @return bool
     */
    private static function validateCodeRequirement($request): bool
    {
        $result = self::extractExclusiveValue($request, 'code', 'x-mo-code');
        if ($result['error']) {
            \Modular\ConnectorDependencies\app('log')->debug('isDirectRequest: Rejected - code in both query and header');
            return \false;
        }
        if (empty($result['value'])) {
            \Modular\ConnectorDependencies\app('log')->debug('isDirectRequest: Rejected - code required for type=oauth');
            return \false;
        }
        // Many social plugins use 'code' as a query parameter for their OAuth
        // callbacks (e.g. Facebook, Instagram). To avoid conflicts with
        // these plugins, we will ignore the 'code' parameter
        // in the query string for type=oauth requests.
        if (isset($_GET['code'])) {
            unset($_GET['code']);
        }
        if (isset($_REQUEST['code'])) {
            unset($_REQUEST['code']);
        }
        return \true;
    }
    /**
     * Validate that state parameter exists for OAuth (can be empty string).
     *
     * @param \Illuminate\Http\Request $request
     * @return bool
     */
    private static function validateStateRequirement($request): bool
    {
        if (!$request->has('state')) {
            \Modular\ConnectorDependencies\app('log')->debug('isDirectRequest: Rejected - state parameter required for type=oauth');
            return \false;
        }
        return \true;
    }
    /**
     * @param \Illuminate\Http\Request $request
     * @return bool
     */
    private static function validateLoopbackNumber($request): bool
    {
        $lbn = $request->get('lbn');
        if (empty($lbn)) {
            \Modular\ConnectorDependencies\app('log')->debug('isDirectRequest: Rejected - lbn parameter is required for type=lb');
            return \false;
        }
        if (!preg_match('/^[a-f0-9]{32}$/i', $lbn)) {
            \Modular\ConnectorDependencies\app('log')->debug('isDirectRequest: Rejected - invalid lbn format', ['lbn' => $lbn, 'expected' => '32 hex characters']);
            return \false;
        }
        return \true;
    }
    /**
     * @param \Illuminate\Http\Request $request
     * @param array $typeData
     * @param array $authData
     * @return bool
     */
    private static function validateAllowedParameters($request, array $typeData, array $authData): bool
    {
        $type = $typeData['type'];
        $hasTypeQuery = $typeData['hasTypeQuery'];
        $hasSigParam = $authData['hasSigParam'];
        $allowedParams = self::buildAllowedParameters($type, $hasTypeQuery, $hasSigParam, $request);
        $allParams = array_keys($request->query->all());
        foreach ($allParams as $param) {
            if (!in_array($param, $allowedParams, \true)) {
                \Modular\ConnectorDependencies\app('log')->debug('isDirectRequest: Rejected - unexpected parameter', ['param' => $param, 'allowed' => $allowedParams]);
                return \false;
            }
            // Skip empty validation for 'state' - it can be empty but must exist
            if ($param === 'state') {
                continue;
            }
            $value = $request->get($param);
            if ($value === null || $value === '') {
                \Modular\ConnectorDependencies\app('log')->debug('isDirectRequest: Rejected - empty required parameter', ['param' => $param]);
                return \false;
            }
        }
        return \true;
    }
    /**
     * @param string $type
     * @param bool $hasTypeQuery
     * @param bool $hasSigParam
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    private static function buildAllowedParameters(string $type, bool $hasTypeQuery, bool $hasSigParam, $request): array
    {
        $baseParams = ['origin'];
        if ($hasTypeQuery) {
            $baseParams[] = 'type';
        }
        switch ($type) {
            case 'request':
                $params = $baseParams;
                if ($request->has('mrid')) {
                    $params[] = 'mrid';
                }
                if ($hasSigParam) {
                    $params[] = 'sig';
                }
                return $params;
            case 'lb':
                return array_merge($baseParams, ['lbn']);
            case 'oauth':
                $params = $baseParams;
                if ($request->has('code')) {
                    $params[] = 'code';
                }
                // state is required but can be empty
                $params[] = 'state';
                return $params;
            default:
                return $baseParams;
        }
    }
    /**
     * Generate maintenance mode code for .maintenance file.
     *
     * This generates the PHP code that allows Modular Connector communications
     *
     * Replicates the validation logic from isDirectRequest() but for the
     * .maintenance file context (before WordPress and plugins load).
     *
     * @param bool $indefinite Whether maintenance mode is indefinite
     * @return string PHP code for .maintenance file
     */
    public static function generateMaintenance(bool $indefinite = \false): string
    {
        $upgradingValue = $indefinite ? 'time()' : time();
        return <<<PHP
        <?php
        \$upgrading = {$upgradingValue};
        
        // Allow Modular Connector communications during maintenance
        // Helper function to get header value (case-insensitive)
        function getHeader(\$name) {
            \$name = "HTTP_" . strtoupper(str_replace("-", "_", \$name));
            return isset(\$_SERVER[\$name]) ? \$_SERVER[\$name] : null;
        }
        
        // Validate entry point (wp-load.php only)
        \$scriptName = isset(\$_SERVER["SCRIPT_NAME"]) ? \$_SERVER["SCRIPT_NAME"] : (isset(\$_SERVER["PHP_SELF"]) ? \$_SERVER["PHP_SELF"] : "");
        \$requestUri = isset(\$_SERVER["REQUEST_URI"]) ? \$_SERVER["REQUEST_URI"] : "";
        
        \$validEntryPoint = false;
        
        // Standard case: script name ends with wp-load.php
        if (substr(\$scriptName, -11) === "wp-load.php") {
            \$validEntryPoint = true;
        }
        
        // Subdirectory case: index.php with path ending in wp-load.php
        if (\$scriptName === "/index.php") {
            // Extract path from REQUEST_URI (remove query string)
            \$path = strtok(\$requestUri, "?");
            // Check if path ends with wp-load.php
            if (substr(\$path, -11) === "wp-load.php") {
                \$validEntryPoint = true;
            }
        }
        
        if (!\$validEntryPoint) {
            return; // Not a valid entry point, maintain maintenance mode
        }
        
        // Get origin (only from query, per HttpUtils logic)
        \$origin = isset(\$_GET["origin"]) ? \$_GET["origin"] : null;
        
        if (\$origin === "mo") {
            // Get type (query XOR header, never both)
            \$typeQuery = isset(\$_GET["type"]) ? \$_GET["type"] : null;
            \$typeHeader = getHeader("x-mo-type");
            \$hasTypeQuery = \$typeQuery !== null;
            \$hasTypeHeader = \$typeHeader !== null;
        
            // XOR: one or the other, never both
            if (\$hasTypeQuery && !\$hasTypeHeader) {
                \$type = \$typeQuery;
            } elseif (\$hasTypeHeader && !\$hasTypeQuery) {
                \$type = \$typeHeader;
            } else {
                // Both or neither - invalid
                \$type = null;
            }
        
            // Only allow type=request, type=lb, or type=oauth
            if (\$type === "request") {
                // Get mrid (query XOR header)
                \$mridQuery = isset(\$_GET["mrid"]) ? \$_GET["mrid"] : null;
                \$mridHeader = getHeader("x-mo-mrid");
                \$hasMridQuery = \$mridQuery !== null;
                \$hasMridHeader = \$mridHeader !== null;
        
                // XOR validation
                if (\$hasMridQuery && !\$hasMridHeader) {
                    \$mrid = \$mridQuery;
                } elseif (\$hasMridHeader && !\$hasMridQuery) {
                    \$mrid = \$mridHeader;
                } else {
                    \$mrid = null;
                }
        
                // Validate UUID format
                if (\$mrid && preg_match("/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\$/i", \$mrid)) {
                    // Additionally check for authentication presence (sig OR x-mo-authentication)
                    // We do not validate JWT signature here (no client_secret access)
                    // Plugin will validate authentication after bypass
                    \$hasSig = isset(\$_GET["sig"]);
                    \$hasAuthHeader = getHeader("x-mo-authentication") !== null;
        
                    if (\$hasSig || \$hasAuthHeader) {
                        \$upgrading = 0; // Bypass maintenance for valid Modular request
                    }
                }
            } elseif (\$type === "lb") {
                // type=lb requires lbn (only query, no header alternative)
                \$lbn = isset(\$_GET["lbn"]) ? \$_GET["lbn"] : null;
        
                // Validate 32 hex characters
                if (\$lbn && preg_match("/^[a-f0-9]{32}\$/i", \$lbn)) {
                    // Check for authentication header presence
                    \$hasAuthHeader = getHeader("x-mo-authentication") !== null;
        
                    if (\$hasAuthHeader) {
                        \$upgrading = 0; // Bypass maintenance for valid Modular loopback
                    }
                }
            } elseif (\$type === "oauth") {
                // type=oauth requires code (query XOR header) and state (query only, can be empty)
                \$codeQuery = isset(\$_GET["code"]) ? \$_GET["code"] : null;
                \$codeHeader = getHeader("x-mo-code");
                \$hasCodeQuery = \$codeQuery !== null;
                \$hasCodeHeader = \$codeHeader !== null;
        
                // XOR validation for code
                if (\$hasCodeQuery && !\$hasCodeHeader) {
                    \$code = \$codeQuery;
                } elseif (\$hasCodeHeader && !\$hasCodeQuery) {
                    \$code = \$codeHeader;
                } else {
                    \$code = null;
                }
        
                // state must exist (can be empty)
                \$hasState = isset(\$_GET["state"]);
        
                // Validate code exists and state parameter exists
                if (\$code !== null && \$code !== "" && \$hasState) {
                    // Check for authentication header presence
                    \$hasAuthHeader = getHeader("x-mo-authentication") !== null;
        
                    if (\$hasAuthHeader) {
                        \$upgrading = 0; // Bypass maintenance for valid OAuth callback
                    }
                }
            }
            // Any other type value is rejected
        }
        ?>
        PHP;
    }
}
