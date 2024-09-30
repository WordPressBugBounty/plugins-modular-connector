<?php

namespace Modular\Connector\Services\Manager;

use Modular\ConnectorDependencies\Illuminate\Support\Str;

abstract class AbstractManager implements ManagerContract
{
    abstract protected function checkForUpdates();

    /**
     * Makes the necessary WordPress admin includes
     * to handle plugin and themes functionality.
     *
     * @return void
     */
    protected function include(): void
    {
        if (!isset($GLOBALS['wp_version'])) {
            @include_once ABSPATH . WPINC . '/version.php';
        }

        if (!function_exists('get_site_transient') || !function_exists('get_option')) {
            @include_once ABSPATH . WPINC . '/option.php';
        }

        if (!function_exists('get_locale')) {
            @include_once ABSPATH . WPINC . '/update-core.php';
        }

        if (
            !function_exists('wp_version_check') ||
            !function_exists('wp_update_themes') ||
            !function_exists('wp_update_plugins')
        ) {
            @include_once ABSPATH . WPINC . '/update.php';
        }

        if (!function_exists('get_plugins')) {
            @include_once ABSPATH . WPINC . '/plugin.php';
        }

        if (!function_exists('deactivate_plugins') ||
            !function_exists('activate_plugins')) {
            @include_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if (!function_exists('delete_plugins') ||
            !function_exists('request_filesystem_credentials')) {
            @include_once ABSPATH . 'wp-admin/includes/file.php';
        }

        if (
            !function_exists('wp_get_themes') ||
            !function_exists('register_theme_directory')
        ) {
            @include_once ABSPATH . WPINC . '/theme.php';
        }

        if (!function_exists('delete_theme')) {
            @include_once ABSPATH . 'wp-admin/includes/theme.php';
        }
    }

    /**
     * Makes the necessary WordPress upgrader includes
     * to handle plugin and themes functionality.
     *
     * @return void
     */
    protected function includeUpgrader(): void
    {
        $this->include();

        if (!function_exists('WP_Filesystem')) {
            @include_once ABSPATH . 'wp-admin/includes/file.php';
        }

        if (
            !class_exists('Plugin_Upgrader') ||
            !class_exists('WP_Ajax_Upgrader_Skin')
        ) {
            @include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        }

        if (!function_exists('got_mod_rewrite')) {
            @include_once ABSPATH . 'wp-admin/includes/misc.php';
        }

        if (!function_exists('wp_install')) {
            @include_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        // FOR PLUGINS
        if (!function_exists('plugins_api')) {
            @include_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        }

        if (!function_exists('delete_theme')) {
            @include_once ABSPATH . 'wp-admin/includes/theme.php';
        }

        if (!function_exists('wp_category_checklist')) {
            @include_once ABSPATH . 'wp-admin/includes/template.php';
        }

        if (empty($GLOBALS['wp_filesystem'])) {
            WP_Filesystem();
        }
    }

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
        if (!in_array($item, ['core', 'translations'])) {
            $isSuccess = !is_wp_error($result) && !($result instanceof \Throwable);

            if ($action === 'upgrade') {
                $isSuccess = $isSuccess && !empty($result['source']) || $result === true;
            } else if ($action === 'install') {
                $isSuccess = $isSuccess && !empty($result) && isset($result['basename']);

                if ($isSuccess) {
                    $item = $result;
                    $result = null;
                }
            } else if (in_array($action, ['activate', 'deactivate'])) {
                $isSuccess = $isSuccess && !empty($result) && isset($result['status']) && $result['status'] === 'success';
            }

            if ($isSuccess && isset($result['source_files'])) {
                unset($result['source_files']);
            }
        } else {
            $isSuccess = !is_wp_error($result) || $result instanceof \Throwable || $result === true;
        }

        return [
            'item' => $item,
            'type' => Str::singular($type),
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
                    'message' => sprintf('%s in %s on line %s',
                        $error->getMessage(), $error->getFile(), $error->getLine()
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
     * @param \Modular\ConnectorDependencies\Illuminate\Support\Collection $items
     * @param $updatableItems
     * @return array
     */
    final protected function map($type, $items, $updatableItems)
    {
        return $items->map(function ($item, $basename) use ($updatableItems, $type) {
            $newVersion = null;

            if (isset($updatableItems[$basename])) {
                if (is_array($updatableItems[$basename]) && isset($updatableItems[$basename]['new_version'])) {
                    $newVersion = $updatableItems[$basename]['new_version'];
                } else if (isset($updatableItems[$basename]->new_version)) {
                    $newVersion = $updatableItems[$basename]->new_version;
                }
            }

            $homepage = '';
            $status = '';

            if ($type === 'plugin') {
                $homepage = $item['PluginURI'];
                $status = is_plugin_active($basename);
            } else if ($type === 'theme') {
                /**
                 * @var \WP_Theme $item
                 */
                $homepage = $item->get('ThemeURI');

                $theme = wp_get_theme();
                $status = $basename === $theme->get_template();
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
     * @return void
     */
    protected function clearUpdates(string $transientType)
    {
        $data = get_site_transient($transientType);

        set_transient($transientType, $data);
        set_site_transient($transientType, $data);
    }

    /**
     * Returns the existing items that have available updates.
     *
     * @param string $itemType Valid values are 'plugins', 'themes' or 'core'.
     * @return array Array of $itemType base names that have updates available.
     */
    protected function getItemsToUpdate(string $itemType)
    {
        $updatableItems = [];
        $transientType = 'update_' . $itemType;

        $this->clearUpdates($transientType);
        $transient = get_site_transient($transientType);

        if (isset($transient->response) && !empty($transient->response)) {
            foreach ($transient->response as $basename => $data) {
                $updatableItems[$basename] = $data;
            }
        }

        return $updatableItems;
    }
}
