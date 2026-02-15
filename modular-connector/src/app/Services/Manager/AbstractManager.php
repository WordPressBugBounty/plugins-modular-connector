<?php

namespace Modular\Connector\Services\Manager;

use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use Modular\ConnectorDependencies\Illuminate\Support\Str;
use function Modular\ConnectorDependencies\data_get;

abstract class AbstractManager implements ManagerContract
{
    public const THEME = 'theme';
    public const PLUGIN = 'plugin';
    public const CORE = 'core';
    public const TRANSLATION = 'translation';

    /**
     * Parses the bulk upgrade response to determine
     * if the items have been updated or not.
     *
     * @param array $items
     * @param $response
     * @param string $action
     * @param $type
     * @return mixed
     * @throws \Exception
     */
    final protected function parseBulkActionResponse(array $items, $response, $action, $type)
    {
        return array_map(function ($item) use ($response, $action, $type) {
            $result = $response[$item] ?? null;

            return $this->parseActionResponse($item, $result, $action, $type);
        }, $items);
    }

    /**
     * Parsing the upgrade response of the item
     *
     * @param string $item
     * @param $result
     * @param string $action
     * @param $type
     * @return mixed
     * @throws \Exception
     */
    final protected function parseActionResponse(string $item, $result, $action, $type)
    {
        if (!in_array($item, [self::CORE, self::TRANSLATION, 'translations'])) {
            $isSuccess = !is_wp_error($result) && !$result instanceof \Throwable;

            if ($action === 'upgrade') {
                if ($result === true) {
                    $result = new \WP_Error(
                        'up_to_date',
                        'No pending update was found for this item. The update information may have expired or been cleared before the update could run. Click "reload" on the updater to verify whether an update is still pending.'
                    );

                    $isSuccess = false;
                } else {
                    $isSuccess = $isSuccess && !empty($result['source']);
                }
            } elseif ($action === 'install') {
                $isSuccess = $isSuccess && !empty($result) && isset($result['basename']);

                if ($isSuccess) {
                    $item = $result;
                    $result = null;
                }
            } elseif (in_array($action, ['activate', 'deactivate'])) {
                $isSuccess = $isSuccess && !empty($result) && isset($result['status']) && $result['status'] === 'success';
            }

            if ($isSuccess && isset($result['source_files'])) {
                unset($result['source_files']);
            }
        } else {
            $isSuccess = !is_wp_error($result) && !$result instanceof \Throwable || $result === true;
        }

        return [
            'item' => $item,
            'type' => $type,
            'success' => $isSuccess,
            'response' => $this->formatWordPressError($result),
        ];
    }

    /**
     * Receives a WordPress error as $error parameters and returns it in our desired format.
     *
     * @param \WP_Error|mixed $error
     * @return array[]
     * @throws \Exception
     * @depreacted Pending error handler
     */
    final protected function formatWordPressError($error)
    {
        if (is_wp_error($error)) {
            return [
                'error' => [
                    'code' => $error->get_error_code(),
                    'message' => $error->get_error_message(),
                ],
            ];
        } elseif ($error instanceof \Throwable) {
            return [
                'error' => [
                    'code' => $error->getCode(),
                    'message' => sprintf(
                        '%s in %s on line %s',
                        $error->getMessage(),
                        $error->getFile(),
                        $error->getLine()
                    ),
                ],
            ];
        }

        return $error;
    }

    /**
     * By receiving a list of actually $installedItems and the $updatableItems, it returns the basic information
     * including the 'new_version' if available.
     *
     * @param string $type
     * @param \Illuminate\Support\Collection $items
     * @param $updatableItems
     * @return array
     */
    final protected function map($type, $items, $updatableItems)
    {
        return $items->map(function ($item, $basename) use ($updatableItems, $type) {
            $newVersion = null;

            if (isset($updatableItems[$basename])) {
                $newVersion = data_get($updatableItems[$basename], 'update.new_version');
            }

            $homepage = '';
            $status = '';

            if ($type === ManagerPlugin::PLUGIN) {
                $homepage = $item['PluginURI'];
                $status = is_plugin_active($basename);
            } elseif ($type === ManagerTheme::THEME) {
                /**
                 * @var \WP_Theme $item
                 */
                $homepage = $item->get('ThemeURI');

                $theme = wp_get_theme();
                $status = $basename === $theme->get_stylesheet();
            }

            return [
                'name' => Str::ascii($item['Name']),
                'description' => Str::ascii($item['Description']),
                'author' => Str::ascii($item['Author']),
                'author_uri' => $item['AuthorURI'],
                'basename' => $basename,
                'new_version' => $newVersion,
                'requires_php' => $item['RequiresPHP'],
                'requires_wp' => $item['RequiresWP'],
                'status' => $status ? 'active' : 'inactive',
                'homepage' => $homepage,
                'version' => $item['Version'],
            ];
        })->values()->toArray();
    }

    /**
     * Clears the update cache.
     *
     * @return array
     */
    protected function tryFillMissingUpdates($updatableItems, $type)
    {
        /**
         * Get the REAL transient that WordPress uses for updates.
         * This transient contains the actual installed plugins/themes and their versions,
         * which premium plugins need to see to determine if they should add update info.
         *
         * Premium plugins hook into "pre_set_site_transient_update_*" and expect to receive
         * the complete transient object with:
         * - checked: array of installed items and their versions
         * - response: array of available updates
         * - last_checked: timestamp of last check
         *
         * If we pass a dummy/fake transient, premium plugins may not recognize their own
         * plugin in the 'checked' array and skip adding update information.
         */
        $transient = get_site_transient("update_{$type}s");

        if (!is_object($transient)) {
            $transient = new \stdClass();
            $transient->last_checked = time();
            $transient->checked = [];
            $transient->response = [];
        }

        /**
         * Premium plugins often use the "pre_set_site_transient_update_$TYPEs" filter
         * to inject their update information. WordPress calls this filter when SAVING
         * the transient, but we simulate it here to capture premium plugin updates
         * that aren't included in the standard wordpress.org response.
         */
        try {
            $updatesFromHook = apply_filters("pre_set_site_transient_update_{$type}s", $transient, "update_{$type}s");
        } catch (\Throwable $e) {
            $updatesFromHook = $transient;

            Log::error(sprintf('%s on line %s: %s', $e->getFile(), $e->getLine(), $e->getMessage()));
        }

        switch ($type) {
            case ManagerPlugin::PLUGIN:
                $allItems = get_plugins();
                break;
            case ManagerTheme::THEME:
                $allItems = wp_get_themes();
                break;
            default:
                $allItems = [];
                break;
        }

        if (!is_array($allItems)) {
            $allItems = (array)$allItems;
        }

        foreach ($allItems as $basename => $data) {
            /**
             * If we already have update info from the standard check, or if
             * the premium plugin did not add any update info, skip it.
             */
            if (isset($updatableItems[$basename]) || !isset($updatesFromHook->response[$basename])) {
                continue;
            }

            $response = data_get($updatesFromHook, 'response', $data);
            $updateInfo = $response[$basename] ?? [];

            /**
             * An empty “package” means the plugin or theme doesn't support automatic updates
             * (no download link available). For premium items, that field typically
             * depends on a valid access key. However, we still want to show the update
             * is available, even if automatic update isn't possible.
             * */
            $updatableItems[$basename] = $type === ManagerPlugin::PLUGIN ? (object)$data : wp_get_theme($basename);
            $updatableItems[$basename]->update = $updateInfo;
        }

        return $updatableItems;
    }

    /**
     * Returns the existing items that have available updates.
     *
     * @param string $itemType Valid values are 'plugin', 'theme' or 'core'.
     * @return array Array of $itemType base names that have updates available.
     */
    protected function getItemsToUpdate(string $itemType)
    {
        $itemsToUpdate = [];

        switch ($itemType) {
            case ManagerTheme::THEME:
                $itemsToUpdate = $this->tryFillMissingUpdates(get_theme_updates(), $itemType);
                break;
            case ManagerPlugin::PLUGIN:
                $itemsToUpdate = $this->tryFillMissingUpdates(get_plugin_updates(), $itemType);
                break;
        }

        return $itemsToUpdate;
    }
}
