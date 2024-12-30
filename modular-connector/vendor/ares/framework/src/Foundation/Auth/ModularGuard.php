<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Auth;

use Modular\ConnectorDependencies\Illuminate\Contracts\Auth\Authenticatable;
use Modular\ConnectorDependencies\Illuminate\Contracts\Auth\Guard;
use Modular\Connector\Helper\OauthClient;
class ModularGuard implements Guard
{
    protected $user = null;
    /**
     * Determine if the current user is authenticated.
     *
     * @return bool
     */
    public function check()
    {
        return !is_null($this->user());
    }
    /**
     * Determine if the current user is a guest.
     *
     * @return bool
     */
    public function guest()
    {
        return is_null($this->user());
    }
    public function user()
    {
        $client = OauthClient::getClient();
        try {
            $client->validateOrRenewAccessToken();
            $this->user = ['id' => $client->getClientId()];
        } catch (\Throwable $e) {
            // Silence is golden
            return null;
        }
        return $this->user;
    }
    public function id()
    {
        return $this->user()['id'] ?? null;
    }
    public function validate(array $credentials = [])
    {
        // TODO: Implement validate() method.
    }
    public function setUser(Authenticatable $user)
    {
        // TODO: Implement setUser() method.
    }
}
