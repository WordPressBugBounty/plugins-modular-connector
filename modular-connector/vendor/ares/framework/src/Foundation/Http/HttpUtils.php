<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Http;

use Modular\ConnectorDependencies\Illuminate\Support\Facades\Cache;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use Modular\ConnectorDependencies\Illuminate\Support\Str;
class HttpUtils
{
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
     * @deprecated Use isCron() & isAjax() instead.
     */
    public static function isAjax(): bool
    {
        $request = \Modular\ConnectorDependencies\app('request');
        return defined('DOING_AJAX') && \DOING_AJAX && Str::startsWith($request->get('action'), 'modular_');
    }
    /**
     * @return bool
     */
    public static function isCron(): bool
    {
        return defined('DOING_CRON') && \DOING_CRON;
    }
    /**
     * @return bool
     */
    public static function isDirectRequest(): bool
    {
        $request = \Modular\ConnectorDependencies\app('request');
        $userAgent = $request->header('User-Agent');
        $userAgentMatches = $userAgent && Str::is('ModularConnector/* (Linux)', $userAgent);
        $originQuery = $request->has('origin') && $request->get('origin') === 'mo';
        $isFromQuery = ($originQuery || $userAgentMatches) && $request->has('type');
        // When is wp-load.php request
        if ($isFromQuery) {
            return \true;
        }
        // TODO Now we use Laravel routes but we can't directly use the routes
        $isFromSegment = \false && $request->segment(1) === 'api' && $request->segment(2) === 'modular-connector';
        if ($isFromSegment) {
            return \true;
        }
        return \false;
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
    public static function forceCloseConnection(): void
    {
        ignore_user_abort(\true);
        if (\function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (\function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
    }
    /**
     * @return void
     */
    public static function restartQueue(int $timestamp)
    {
        Cache::forever('illuminate:queue:restart', $timestamp);
        Log::info('Broadcasting queue restart signal.');
    }
}
