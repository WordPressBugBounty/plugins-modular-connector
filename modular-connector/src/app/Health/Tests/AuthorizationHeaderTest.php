<?php

namespace Modular\Connector\Health\Tests;

use Modular\Connector\Health\AbstractHealthTest;

class AuthorizationHeaderTest extends AbstractHealthTest
{
    /**
     * @return string
     */
    public function identifier()
    {
        return 'authorization_header';
    }

    /**
     * @return array
     */
    public function run()
    {
        // Sites behind .htpasswd block the probe request, so core would
        // surface a false "recommended" — short-circuit to good.
        if (function_exists('wp_is_site_protected_by_basic_auth') && \wp_is_site_protected_by_basic_auth()) {
            return $this->result('good');
        }

        $core = $this->siteHealth()->get_test_authorization_header();

        $status = $this->fromCoreStatus(isset($core['status']) ? (string)$core['status'] : null);

        return $this->result($status, $status !== 'good' ? [
            'response_code' => 0,
            'error' => isset($core['description'])
                ? \wp_strip_all_tags((string)$core['description'])
                : null,
        ] : null);
    }
}
