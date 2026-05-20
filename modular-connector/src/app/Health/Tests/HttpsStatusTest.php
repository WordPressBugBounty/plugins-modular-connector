<?php

namespace Modular\Connector\Health\Tests;

use Modular\Connector\Health\AbstractHealthTest;

class HttpsStatusTest extends AbstractHealthTest
{
    /**
     * @return string
     */
    public function identifier()
    {
        return 'https_status';
    }

    /**
     * @return array
     */
    public function run()
    {
        // Schemes stay in the payload so API can diagnose "site_url is http
        // but home_url is https" without parsing core's localized description.
        $core = $this->siteHealth()->get_test_https_status();

        $status = $this->fromCoreStatus(isset($core['status']) ? (string)$core['status'] : null);

        return $this->result($status, $status !== 'good' ? [
            'site_url_scheme' => \wp_parse_url(\site_url(), PHP_URL_SCHEME),
            'home_url_scheme' => \wp_parse_url(\home_url(), PHP_URL_SCHEME),
            'error' => isset($core['description'])
                ? \wp_strip_all_tags((string)$core['description'])
                : null,
        ] : null);
    }
}
