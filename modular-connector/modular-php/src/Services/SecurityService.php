<?php

namespace Modular\SDK\Services;

class SecurityService extends AbstractService
{
    /**
     * @param int|string $siteScanId
     * @param array $data
     * @param array $opts
     * @return mixed
     * @throws \ErrorException
     */
    public function createScanUpload($siteScanId, array $data = [], array $opts = [])
    {
        $opts += [
            'auth' => true,
        ];

        return $this->raw(
            'post',
            $this->buildPath('/site/manager/site-scan/%s/upload', $siteScanId),
            $data,
            $opts
        );
    }
}
