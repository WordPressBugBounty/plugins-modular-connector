<?php

namespace Modular\SDK\Services;

class LinkingService extends AbstractService
{
    /**
     * Register site with Modular DS using a linking token.
     *
     * @param string $token The linking token
     * @param string $uri The site URL
     * @param string $name The site name
     * @param string $provider The provider type (default: 'wp')
     * @return object Response containing client_id and client_secret
     */
    public function register(string $token, string $uri, string $name, string $provider = 'wp'): object
    {
        return $this->raw(
            'post',
            $this->buildPath('/site/manager/linking/register'),
            [
                'token' => $token,
                'uri' => $uri,
                'name' => $name,
                'provider' => $provider,
            ],
        );
    }

    /**
     * Confirm that credentials were stored successfully.
     *
     * @param string $token The linking token
     * @param int|string $siteId The site ID from register response
     * @return object
     */
    public function confirm(string $token, $siteId): object
    {
        return $this->raw(
            'post',
            $this->buildPath('/site/manager/linking/confirm'),
            [
                'token' => $token,
                'client_id' => $this->getClient()->getClientId(),
                'site_id' => $siteId,
            ]
        );
    }
}
