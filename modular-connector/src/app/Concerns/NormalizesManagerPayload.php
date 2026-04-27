<?php

namespace Modular\Connector\Concerns;

use Modular\Connector\Services\Manager\AbstractManager;
use function Modular\ConnectorDependencies\data_get;

trait NormalizesManagerPayload
{
    /**
     * Legacy payload keys mapped to their type constant.
     */
    private static array $legacyTypeMap = [
        'plugins' => AbstractManager::PLUGIN,
        'themes' => AbstractManager::THEME,
        'core' => AbstractManager::CORE,
        'translations' => AbstractManager::TRANSLATION,
    ];

    /**
     * Normalizes both legacy and explicit manage payload formats into a consistent structure.
     *
     * @param mixed $body
     * @return array{type: string|null, items: mixed, extra: array}
     */
    protected function normalizeManagePayload($body): array
    {
        $type = data_get($body, 'type');
        $items = data_get($body, 'items');

        if (empty($type)) {
            foreach (self::$legacyTypeMap as $key => $mappedType) {
                if (!empty(data_get($body, $key))) {
                    $type = $mappedType;
                    $items = data_get($body, $key);
                    break;
                }
            }
        }

        return [
            'type' => $type,
            'items' => $items,
            'extra' => (array)data_get($body, 'extra', []),
        ];
    }

    /**
     * Normalizes the install payload accepting both snake_case and legacy camelCase keys.
     *
     * @param mixed $body
     * @return array{type: string|null, name: string|null, download_link: string|null, overwrite: bool, activate: bool, clean_cache: bool, patchstack_license_key: string|null}
     */
    protected function normalizeInstallPayload($body): array
    {
        return [
            'type' => data_get($body, 'type'),
            'name' => data_get($body, 'name'),
            'download_link' => data_get($body, 'download_link', data_get($body, 'downloadLink')),
            'overwrite' => (bool)data_get($body, 'overwrite', true),
            'activate' => (bool)data_get($body, 'activate', false),
            'clean_cache' => (bool)data_get($body, 'clean_cache', data_get($body, 'cleanCache', false)),
            'patchstack_license_key' => data_get($body, 'patchstack_license_key', data_get($body, 'patchstackLicenseKey')),
        ];
    }
}
