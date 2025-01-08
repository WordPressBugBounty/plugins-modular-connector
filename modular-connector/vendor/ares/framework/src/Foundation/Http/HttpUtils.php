<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Http;

use Modular\ConnectorDependencies\Illuminate\Support\Collection;
use Modular\ConnectorDependencies\Symfony\Component\HttpFoundation\InputBag;
use Modular\ConnectorDependencies\Symfony\Component\HttpFoundation\Response;
class HttpUtils
{
    /**
     * @param bool $changeTimeLimit
     * @return void
     */
    public static function configMaxLimit(bool $changeTimeLimit = \true): void
    {
        if ($changeTimeLimit && function_exists('set_time_limit')) {
            @set_time_limit(900);
        }
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(\true);
        }
    }
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
            $memoryLimit = '128M';
        }
        if (!$memoryLimit || intval($memoryLimit) === -1) {
            // Unlimited, set to 3GB.
            $memoryLimit = '3200M';
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
    public static function isAjax(): bool
    {
        $request = \Modular\ConnectorDependencies\app('request');
        /**
         * @var \Illuminate\Config\Repository $config
         */
        $config = \Modular\ConnectorDependencies\app('config');
        $isFromCron = $config->get('app.router.ajax', fn() => fn($request) => \false);
        return $isFromCron($request);
    }
    /**
     * @return bool
     */
    public static function isCron(): bool
    {
        $request = \Modular\ConnectorDependencies\app('request');
        /**
         * @var \Illuminate\Config\Repository $config
         */
        $config = \Modular\ConnectorDependencies\app('config');
        $isFromCron = $config->get('app.router.cron', fn() => fn($request) => \false);
        return $isFromCron($request);
    }
    /**
     * @return true
     */
    public static function isDirectRequest(): bool
    {
        $request = \Modular\ConnectorDependencies\app('request');
        /**
         * @var \Illuminate\Config\Repository $config
         */
        $config = \Modular\ConnectorDependencies\app('config');
        /**
         * [
         * 'origin' => 'mo',
         * 'type' => fn($value) => !empty($value),
         * 'mrid' => fn($value) => !empty($value),
         * ],
         */
        $queryParams = Collection::make($config->get('app.router.direct', []));
        /**
         * @var InputBag $query
         */
        $query = $request->query;
        $isFromQuery = $query->count() >= count($queryParams) && $queryParams->filter(function ($value, $key) use ($request) {
            if (is_callable($value)) {
                return $request->has($key) && $value($request->get($key));
            }
            return $request->has($key) && $request->get($key) === $value;
        })->count() === $queryParams->count();
        // When is wp-load.php request
        if ($isFromQuery) {
            return \true;
        }
        $isFromSegment = $config->get('app.router.segments', fn() => fn($request) => \false);
        if ($isFromSegment($request)) {
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
}
