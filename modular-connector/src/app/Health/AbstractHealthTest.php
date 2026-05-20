<?php

namespace Modular\Connector\Health;

use Modular\Connector\Health\Contracts\HealthTestContract;

abstract class AbstractHealthTest implements HealthTestContract
{
    /**
     * @param string $status
     * @param array|null $data
     * @return array
     */
    protected function result($status, array $data = null)
    {
        return [
            'status' => $status,
            'data' => $status !== 'good' ? $data : null,
        ];
    }

    /**
     * Map a WP_Site_Health verdict ("good"/"recommended"/"critical") to our
     * own enum. Anything unexpected (older WP plugins extending Site Health
     * with custom statuses) collapses to "recommended" so the API never sees
     * a value it does not know how to render.
     *
     * @param string|null $coreStatus
     * @return string
     */
    protected function fromCoreStatus($coreStatus)
    {
        return in_array($coreStatus, ['good', 'recommended', 'critical'], true)
            ? $coreStatus
            : 'recommended';
    }

    /**
     * Wrap a WP_Site_Health call with a wall-clock timer so the payload can
     * keep emitting `response_time_ms` even though core does not expose it.
     * Core swallows the underlying HTTP response code as well, so callers
     * always emit `response_code: 0` — the API contract is preserved on
     * shape, not on every individual value.
     *
     * @param callable $coreCall
     * @return array{result:mixed,response_time_ms:int}
     */
    protected function timedCoreCall(callable $coreCall)
    {
        $start = microtime(true);
        $result = $coreCall();
        $elapsed = (int)round((microtime(true) - $start) * 1000);

        return [
            'result' => $result,
            'response_time_ms' => $elapsed,
        ];
    }

    /**
     * `wp-admin/includes` is not autoloaded outside admin context. When the
     * queue worker runs this test from cron or a loopback, the class file
     * has to be required explicitly or `WP_Site_Health::get_instance()`
     * fatals with "Class not found".
     *
     * @return \WP_Site_Health
     */
    protected function siteHealth()
    {
        if (!class_exists('WP_Site_Health')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
        }

        return \WP_Site_Health::get_instance();
    }
}
