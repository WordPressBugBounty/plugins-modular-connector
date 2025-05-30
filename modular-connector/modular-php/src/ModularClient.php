<?php

namespace Modular\SDK;

use Modular\ConnectorDependencies\Carbon\Carbon;
use Modular\ConnectorDependencies\GuzzleHttp\Client;
use Modular\Connector\Facades\Server;
use Modular\SDK\Services\CoreServiceFactory;
use Modular\SDK\Support\ApiHelper;

/**
 * @property \Modular\SDK\Services\OauthService $oauth
 * @property \Modular\SDK\Services\WordPressService $wordpress
 * @property \Modular\SDK\Services\BackupService $backup
 */
class ModularClient implements ModularClientInterface
{
    /**
     * @var string default base URL for Modular's API
     */
    public const DEFAULT_API_BASE = 'https://api.modulards.com';

    /**
     * @var string default base URL for Modular's API
     */
    public const LOCAL_API_BASE = 'https://api.modulards.dev';

    /**
     * @var string default base URL for Modular's API
     */
    public const STG_API_BASE = 'https://api.staging.modulards.com';

    /**
     * @var Client
     */
    private Client $client;

    /**
     * @var array{client_id: string, client_secret: string, redirect_uri: string}
     */
    private array $oauthClient;

    /**
     * @var array{access_token: string, refresh_token: string}
     */
    private array $oauthToken;

    /**
     * @var null|CoreServiceFactory
     */
    private ?CoreServiceFactory $coreServiceFactory = null;

    /**
     * @param array $config
     */
    public function __construct(array $config)
    {
        $config = \array_merge($this->getDefaultConfig(), $config);

        if ($config['env'] === 'local') {
            $config['base_uri'] = static::LOCAL_API_BASE;
        } elseif ($config['env'] === 'stg') {
            $config['base_uri'] = static::STG_API_BASE;
        }

        $this->oauthClient = $config['oauth_client'];
        $this->oauthToken = $config['oauth_token'];

        $this->client = new Client([
            'verify' => false,
            'base_uri' => rtrim($config['base_uri'], '/'),
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => sprintf('ModularConnectorPluginWP/%s', Server::connectorVersion()),
                'X-Client-Id' => $this->getClientId(),
                'X-Redirect-Uri' => $this->getClientRedirectUri(),
            ],
            'allow_redirects' => [
                'max' => 5,
                'strict' => true,
                'referer' => false,
                'protocols' => ['http', 'https'],
                'track_redirects' => false,
            ],
        ]);
    }

    /**
     * @param $name
     *
     * @return mixed|Services\AbstractServiceFactory|null
     */
    public function __get($name)
    {
        if ($this->coreServiceFactory === null) {
            $this->coreServiceFactory = new CoreServiceFactory($this);
        }

        return $this->coreServiceFactory->{$name};
    }

    /**
     * @return string
     */
    public function getClientId()
    {
        return $this->oauthClient['client_id'] ?? '';
    }

    /**
     * @return string
     */
    public function getClientSecret()
    {
        return $this->oauthClient['client_secret'];
    }

    /**
     * @return string
     */
    public function getClientRedirectUri()
    {
        return $this->oauthClient['redirect_uri'] ?? '';
    }

    /**
     * @return string
     */
    public function getAccessToken()
    {
        return $this->oauthToken['access_token'];
    }

    /**
     * @return string
     */
    public function getRefreshToken()
    {
        return $this->oauthToken['refresh_token'];
    }

    /**
     * @return Carbon
     */
    public function getExpiresIn()
    {
        $expires_in = intval($this->oauthToken['expires_in']);

        return Carbon::createFromTimestamp($expires_in, 'utc');
    }

    /**
     * @return Carbon|null
     */
    public function getConnectedAt()
    {
        $tz = wp_date('T');
        $connectedAt = intval($this->oauthClient['connected_at']);

        return $connectedAt ? Carbon::parse($connectedAt)->setTimezone($tz) : null;
    }

    /**
     * @return Carbon|null
     */
    public function getUsedAt()
    {
        $tz = wp_date('T');
        $usedAt = intval($this->oauthClient['used_at']);

        return $usedAt ? Carbon::parse($usedAt)->setTimezone($tz) : null;
    }

    /**
     * @return bool
     */
    public function isExpired(): bool
    {
        return $this->getExpiresIn()->isPast();
    }

    /**
     * @param string $value
     * @return $this
     */
    public function setClientId(string $value)
    {
        $this->oauthClient['client_id'] = $value;

        return $this;
    }

    /**
     * @param string $value
     * @return $this
     */
    public function setClientSecret(string $value)
    {
        $this->oauthClient['client_secret'] = $value;

        return $this;
    }

    /**
     * @param string $value
     * @return $this
     */
    public function setAccessToken(string $value)
    {
        $this->oauthToken['access_token'] = $value;

        return $this;
    }

    /**
     * @param string $value
     * @return $this
     */
    public function setRefreshToken(string $value)
    {
        $this->oauthToken['refresh_token'] = $value;

        return $this;
    }

    /**
     * @return $this
     */
    public function setExpiresIn(?int $value)
    {
        $this->oauthToken['expires_in'] = $value ? Carbon::now()->utc()->addSeconds($value)->timestamp : $value;

        return $this;
    }

    /**
     * @return $this
     */
    public function setConnectedAt(?Carbon $value)
    {
        $this->oauthClient['connected_at'] = $value instanceof Carbon ? $value->utc()->timestamp : null;

        return $this;
    }

    /**
     * @return mixed
     */
    public function save()
    {
        $connectedAt = $this->getConnectedAt();

        update_option('_modular_connection_client_id', $this->getClientId());
        update_option('_modular_connection_client_secret', $this->getClientSecret());

        update_option('_modular_connection_access_token', $this->getAccessToken());
        update_option('_modular_connection_refresh_token', $this->getRefreshToken());
        update_option('_modular_connection_expires_in', $this->getExpiresIn()->timestamp);

        update_option('_modular_connection_connected_at', $connectedAt ? $connectedAt->timestamp : null);
        update_option('_modular_connection_used_at', !empty($connectedAt) ? Carbon::now()->timestamp : null);
    }

    /**
     * @return array<string, mixed>
     */
    private function getDefaultConfig()
    {
        return [
            'oauth_client' => [
                'client_id' => '',
                'client_secret' => '',
                'redirect_uri' => '',
                'connected_at' => null,
                'used_at' => null,
            ],
            'oauth_token' => [
                'expires_in' => 0,
                'access_token' => '',
                'refresh_token' => '',
            ],
            'base_uri' => self::DEFAULT_API_BASE,
            'env' => 'production',
        ];
    }

    /**
     * @return true|void
     * @throws \ErrorException
     */
    public function validateOrRenewAccessToken()
    {
        if (!$this->isExpired()) {
            return true;
        }

        $this->oauth->renewAccessToken();
    }

    /**
     * Sends a request to WordPress API.
     *
     * @param string $method the HTTP method
     * @param string $path the path of the request
     * @param array $params the parameters of the request
     * @param array $opts the special modifiers of the request
     *
     * @return mixed
     * @throws \Exception
     */
    public function request($method, $path, array $params, array $opts)
    {
        $opts = $this->parseOpts($params, $opts);

        $response = $this->client->request($method, $path, $opts);

        $data = json_decode($response->getBody()->getContents());

        return ApiHelper::setSdk($this)->setResponse($data)->parser();
    }

    /**
     * Sends a raw request to WordPress API.
     *
     * @param string $method the HTTP method
     * @param string $path the path of the request
     * @param array $params the parameters of the request
     * @param array $opts the special modifiers of the request
     *
     * @return mixed
     * @throws \Exception
     */
    public function raw($method, $path, array $params, array $opts)
    {
        $opts = $this->parseOpts($params, $opts);

        $response = $this->client->request($method, $path, $opts);

        return json_decode($response->getBody()->getContents());
    }

    /**
     * @param array $params
     * @param array $opts
     * @return array
     */
    protected function parseOpts(array $params, array $opts): array
    {
        if (!isset($opts['query'])) {
            $opts['query'] = [];
        }

        if (!isset($opts['headers'])) {
            $opts['headers'] = [];
        }

        if (!empty($opts['auth'])) {
            $opts['headers'] += [
                'Authorization' => 'Bearer ' . $this->getAccessToken(),
            ];

            unset($opts['auth']);
        }

        return array_merge($opts, [
            'form_params' => $params,
        ]);
    }
}
