<?php

namespace Modular\SDK\Services;

/**
 * @property \Modular\SDK\Services\OauthService $oauth
 * @property \Modular\SDK\Services\WordPressService $wordpress
 * @property \Modular\SDK\Services\BackupService $backup
 * @property \Modular\SDK\Services\LinkingService $linking
 */
class CoreServiceFactory extends AbstractServiceFactory
{
    /**
     * @var array<string, string>
     */
    private static array $classMap = [
        'oauth' => OauthService::class,
        'wordpress' => WordPressService::class,
        'backup' => BackupService::class,
        'linking' => LinkingService::class,
    ];

    /**
     * @param string $name
     *
     * @return mixed|string|null
     */
    protected function getServiceClass(string $name): ?string
    {
        return array_key_exists($name, self::$classMap) ? self::$classMap[$name] : null;
    }
}
