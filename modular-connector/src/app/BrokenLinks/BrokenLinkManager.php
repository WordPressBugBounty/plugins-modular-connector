<?php

namespace Modular\Connector\BrokenLinks;

use Modular\Connector\BrokenLinks\Ndjson\NdjsonDriver;
use Modular\ConnectorDependencies\Carbon\Carbon;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Cache;
use Modular\ConnectorDependencies\Illuminate\Support\Manager;

/**
 * Each scan is identified by its `site_broken_link_scan` (int, assigned by API).
 * The cancel marker is keyed by the same id so two concurrent scans cancel
 * independently.
 *
 * The cancel flag lives on the WordPress cache driver so it survives the gap
 * between the HTTP request that posts the cancel and the wp-cron tick that
 * picks up the next batch.
 */
class BrokenLinkManager extends Manager
{
    /**
     * @return string
     */
    public function getDefaultDriver()
    {
        return 'ndjson';
    }

    /**
     * @return NdjsonDriver
     */
    public function createNdjsonDriver()
    {
        return new NdjsonDriver();
    }

    /**
     * Mark a scan as cancelled. In-flight ProcessBrokenLinkBatchJob ticks read
     * this flag at the top of each iteration and exit early.
     *
     * @param int|null $siteBrokenLinkScan
     * @return void
     */
    public function cancel($siteBrokenLinkScan)
    {
        if (empty($siteBrokenLinkScan)) {
            return;
        }

        Cache::put(
            $this->cancelKey((int)$siteBrokenLinkScan),
            true,
            Carbon::now()->addDay()
        );
    }

    /**
     * @param int|null $siteBrokenLinkScan
     * @return bool
     */
    public function isCancelled($siteBrokenLinkScan)
    {
        if (empty($siteBrokenLinkScan)) {
            return false;
        }

        return (bool)Cache::get($this->cancelKey((int)$siteBrokenLinkScan), false);
    }

    /**
     * @param int $siteBrokenLinkScan
     * @return string
     */
    private function cancelKey($siteBrokenLinkScan)
    {
        return '_blc_cancelled_scan_' . (int)$siteBrokenLinkScan;
    }
}
