<?php

namespace Modular\Connector\Services\Manager;

use Modular\Connector\WordPress\ModularPluginUpgrader;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\ScreenSimulation;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\ServerSetup;
use Modular\ConnectorDependencies\Illuminate\Support\Collection;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;

/**
 * Handles all functionality related to WordPress Plugins.
 */
class ManagerPlugin extends AbstractManager
{
    /**
     * Returns a list with the installed plugins in the webpage, including the new version if available.
     *
     * @return array
     */
    public function all(bool $checkUpdates = true)
    {
        ScreenSimulation::includeUpgrader();

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if (!function_exists('get_plugin_updates')) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }

        $updatablePlugins = $checkUpdates ? $this->getItemsToUpdate(self::PLUGIN) : [];
        $plugins = Collection::make(get_plugins());

        // TODO Get drop-ins and must-use plugins.
        return $this->map(self::PLUGIN, $plugins, $updatablePlugins);
    }

    /**
     * @param string $downloadLink
     * @param bool $overwrite
     * @return array|mixed
     * @throws \Exception
     */
    public function install(string $downloadLink, bool $overwrite = true)
    {
        ScreenSimulation::includeUpgrader();

        ServerSetup::clean();

        add_filter('upgrader_package_options', function ($options) use ($overwrite) {
            $options['clear_destination'] = $overwrite;

            return $options;
        });

        if (is_multisite() && !is_main_site()) {
            $error = new \WP_Error('trying_plugin_installation_from_child_site', 'No plugins can be installed from a child site on a multisite.');

            return $this->parseActionResponse($downloadLink, $error, 'install', self::PLUGIN);
        }

        try {
            $skin = new \WP_Ajax_Upgrader_Skin();
            $upgrader = new ModularPluginUpgrader($skin);

            // $result is null when the plugin is already installed.
            $result = $upgrader->install($downloadLink, [
                'overwrite_package' => $overwrite,
            ]);

            $data = $upgrader->new_plugin_data;

            if (is_null($result) && !$overwrite) {
                $result = new \WP_Error('plugin_already_installed', 'The plugin is already installed.');
            } elseif (empty($data)) {
                $result = new \WP_Error('no_plugin_installed', 'No plugin installed.');
            }

            if (is_wp_error($result)) {
                return $this->parseActionResponse($downloadLink, $result, 'install', self::PLUGIN);
            }

            // We cannot use $this->all() because this function remaps the plugins.
            $allPlugins = get_plugins();

            $results = [];

            // Some sites may have the same plugin installed with different versions or paths.
            foreach ($allPlugins as $key => $value) {
                if (
                    $value['Name'] === $data['Name'] &&
                    $value['Version'] === $data['Version'] &&
                    $value['RequiresWP'] === $data['RequiresWP'] &&
                    $value['RequiresPHP'] === $data['RequiresPHP'] &&
                    $value['Author'] === $data['Author'] &&
                    $value['AuthorURI'] === $data['AuthorURI']
                ) {
                    $results[] = $key;
                }
            }

            if (count($results) > 1) {
                // Sort the plugins by the most recent modification date.
                usort(
                    $results,
                    fn($a, $b) => filemtime(WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . $b) - filemtime(WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . $a)
                );
            }

            $basename = $results[0] ?? '';

            ServerSetup::clean();

            $updatablePlugins = $this->getItemsToUpdate(static::PLUGIN);
            $data = $this->map(self::PLUGIN, Collection::make([$basename => $data]), $updatablePlugins);

            return $this->parseActionResponse($basename, $data[array_key_first($data)], 'install', self::PLUGIN);
        } catch (\Throwable $e) {
            Log::error($e);

            return $this->parseActionResponse($downloadLink, $e, 'install', self::PLUGIN);
        } finally {
            ServerSetup::logout();
        }
    }

    /**
     * @param \stdClass $items
     * @return mixed
     * @throws \Exception
     */
    public function activate(\stdClass $items)
    {
        ScreenSimulation::includeUpgrader();

        $response = [];

        foreach ($items as $plugin => $args) {
            $silent = $args->silent ?? false;
            $networkWide = is_bool($args->network_wide) ? $args->network_wide : is_plugin_active_for_network($plugin);

            try {
                $result = activate_plugin(
                    $plugin,
                    '',
                    $networkWide && is_main_site(),
                    $silent
                );

                $response[$plugin] = [
                    'status' => !is_wp_error($result) && is_plugin_active($plugin) ? 'success' : 'error',
                ];
            } catch (\Throwable $e) {
                $response[$plugin] = $e;
            }
        }

        return $this->parseBulkActionResponse(array_keys(get_object_vars($items)), $response, 'activate', self::PLUGIN);
    }

    /**
     * @param \stdClass $items
     * @return mixed
     * @throws \Exception
     */
    public function deactivate(\stdClass $items)
    {
        ScreenSimulation::includeUpgrader();

        $response = [];

        foreach ($items as $plugin => $args) {
            $silent = $args->silent ?? false;
            $networkWide = is_bool($args->network_wide) ? $args->network_wide : is_plugin_active_for_network($plugin);

            try {
                deactivate_plugins($plugin, $silent, $networkWide && is_main_site());

                $response[$plugin] = [
                    'status' => is_plugin_inactive($plugin) ? 'success' : 'error',
                ];
            } catch (\Throwable $e) {
                $response[$plugin] = $e;
            }
        }

        return $this->parseBulkActionResponse(array_keys(get_object_vars($items)), $response, 'deactivate', self::PLUGIN);
    }

    /**
     * Makes a bulk upgrade of the provided $plugins to the most recent version. Returns a list of plugins basenames
     * and a 'true' value if they are in the most recent version.
     *
     * @param array $items
     * @return array|false
     * @throws \Exception
     */
    public function upgrade(array $items = [])
    {
        ScreenSimulation::includeUpgrader();

        if (is_multisite() && !is_main_site()) {
            $error = new \WP_Error('trying_plugin_update_from_child_site', 'No plugins can be updated from a child site on a multisite.');

            return $this->parseBulkActionResponse($items, $error, 'upgrade', self::PLUGIN);
        }

        ServerSetup::clean();

        try {
            $skin = new \WP_Ajax_Upgrader_Skin();
            $upgrader = new ModularPluginUpgrader($skin);

            $response = $upgrader->bulk_upgrade($items);
        } catch (\Throwable $e) {
            $response = Collection::make($items)
                ->mapWithKeys(function ($item) use ($e) {
                    return [
                        $item => $e,
                    ];
                })
                ->toArray();
        } finally {
            ServerSetup::clean();
            ServerSetup::logout();
        }

        return $this->parseBulkActionResponse($items, $response, 'upgrade', self::PLUGIN);
    }

    /**
     * @param array $items
     * @return array
     * @throws \Exception
     */
    public function delete(array $items)
    {
        ScreenSimulation::includeUpgrader();

        $response = [];
        $basenamesToDelete = [];

        if (is_multisite() && !is_main_site()) {
            $error = new \WP_Error('trying_plugin_uninstall_from_child_site', 'No plugins can be uninstalled from a child site on a multisite.');

            return $this->parseBulkActionResponse($items, $error, 'delete', self::PLUGIN);
        }

        foreach ($items as $plugin) {
            $result = validate_plugin($plugin);

            if (is_wp_error($result)) {
                $response[$plugin] = $result;
            } else {
                $basenamesToDelete[] = $plugin;
            }
        }

        try {
            $result = delete_plugins($basenamesToDelete);
        } catch (\Throwable $e) {
            $result = $e;
        }

        array_map(function ($plugin) use ($result, &$response) {
            $response[$plugin] = $result === true ? 'success' : (is_wp_error($result) ? $result : 'error');
        }, $basenamesToDelete);

        return $this->parseBulkActionResponse($items, $response, 'delete', self::PLUGIN);
    }
}
