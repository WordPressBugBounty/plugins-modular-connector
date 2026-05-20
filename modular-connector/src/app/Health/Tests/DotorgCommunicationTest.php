<?php

namespace Modular\Connector\Health\Tests;

use Modular\Connector\Health\AbstractHealthTest;

class DotorgCommunicationTest extends AbstractHealthTest
{
    /**
     * @return string
     */
    public function identifier()
    {
        return 'dotorg_communication';
    }

    /**
     * @return array
     */
    public function run()
    {
        $timed = $this->timedCoreCall(
            fn() => $this->siteHealth()->get_test_dotorg_communication()
        );

        $core = $timed['result'];
        $status = $this->fromCoreStatus(isset($core['status']) ? (string)$core['status'] : null);

        return $this->result($status, $status !== 'good' ? [
            'response_code' => 0,
            'response_time_ms' => $timed['response_time_ms'],
            'error' => isset($core['description'])
                ? \wp_strip_all_tags((string)$core['description'])
                : 'Cannot reach api.wordpress.org',
        ] : null);
    }
}
