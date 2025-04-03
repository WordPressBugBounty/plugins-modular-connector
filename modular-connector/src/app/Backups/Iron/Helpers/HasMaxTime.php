<?php

namespace Modular\Connector\Backups\Iron\Helpers;

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
}
