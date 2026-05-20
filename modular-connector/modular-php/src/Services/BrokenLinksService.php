<?php

namespace Modular\SDK\Services;

class BrokenLinksService extends AbstractService
{
    private const PATH_UPLOAD = '/site/manager/broken-links/plugin-scan/%s/upload';

    /**
     * Request a signed PUT URL for a plugin scan NDJSON artifact.
     *
     * @param int $siteBrokenLinkScan Scan identifier the API uses to correlate the upload.
     * @param array $data Optional body payload.
     * @param array $opts Transport options. `auth` is forced on.
     * @return mixed Signed URL string, same shape as BackupService::createUpload().
     */
    public function createUpload(int $siteBrokenLinkScan, array $data = [], array $opts = [])
    {
        $opts += ['auth' => true];

        return $this->raw(
            'post',
            $this->buildPath(self::PATH_UPLOAD, (string)$siteBrokenLinkScan),
            $data,
            $opts
        );
    }
}
