<?php

namespace Modular\Connector\Backups\Iron\Helpers;

use Modular\ConnectorDependencies\Ares\Framework\Foundation\Http\HttpUtils;

trait HasMaxTime
{
    /**
     * @return float
     */
    protected function getCurrentTime()
    {
        return hrtime(\true) / 1000000000.0;
    }

    /**
     * @param $startTime
     * @param $maxTime
     * @return bool
     */
    protected function isTimeExceeded($startTime, $maxTime)
    {
        return $maxTime && hrtime(\true) / 1000000000.0 - $startTime >= $maxTime;
    }

    /**
     * Check if memory usage is above 80% of the limit
     * 
     * @return bool
     */
    protected function checkMemoryUsage(): bool
    {
        $usage = memory_get_usage(true);
        $limit = HttpUtils::maxMemoryLimit();
        
        // If we can't determine the limit, assume no memory pressure
        if ($limit === -1) {
            return false;
        }
        
        // If usage is above 80% of limit
        return $usage > ($limit * 0.8);
    }
}
