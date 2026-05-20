<?php

namespace Modular\Connector\Health\Tests;

use Modular\Connector\Health\AbstractHealthTest;

class LoopbackRequestsTest extends AbstractHealthTest
{
    /**
     * @return string
     */
    public function identifier()
    {
        return 'loopback_requests';
    }

    /**
     * @return array
     */
    public function run()
    {
        // Core avoids wp_cron_lock contention by tagging the probe request.
        $timed = $this->timedCoreCall(
            fn() => $this->siteHealth()->can_perform_loopback()
        );

        $core = $timed['result'];
        $status = $this->fromCoreStatus(isset($core->status) ? (string)$core->status : null);

        return $this->result($status, $status !== 'good' ? [
            'response_code' => 0,
            'response_time_ms' => $timed['response_time_ms'],
            'error' => isset($core->message) ? (string)$core->message : 'Loopback failed',
        ] : null);
    }
}
