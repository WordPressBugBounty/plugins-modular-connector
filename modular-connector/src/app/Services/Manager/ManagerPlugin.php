<?php

namespace Modular\Connector\Services\Manager;

use Modular\ConnectorDependencies\Illuminate\Support\Collection;

/**
 * Handles all functionality related to WordPress Plugins.
 */
class ManagerPlugin extends AbstractManager
{
    const PLUGINS = 'plugins';

    /**
     * Checks for available updates to plugins based on the latest versions hosted on WordPress Repository
     *
     * @return void
     */
    protected function checkForUpdates()
    {
        @wp_update_plugins();
    }

    /**
     * Returns a list with the installed plugins in the webpage, including the new version if available.
     *
     * @return array
     */
    public function all()
    {
        $this->include();
        $this->checkForUpdates();

        $updatablePlugins = $this->getItemsToUpdate(ManagerPlugin::PLUGINS);
        $plugins = Collection::make(get_plugins());

        // TODO Get drop-ins and must-use plugins.
        return $this->map('plugin', $plugins, $updatablePlugins);
    }

    /**
     * @param string $downloadLink
     * @param bool $overwrite
     * @return array|mixed
     * @throws \Exception
     */
    public function install(string $downloadLink, bool $overwrite = true)
    {
        $this->includeUpgrader();

        $skin = new \WP_Ajax_Upgrader_Skin();
        $upgrader = new \Plugin_Upgrader($skin);

        add_filter('upgrader_package_options', function ($options) use ($overwrite) {
            $options['clear_destination'] = $overwrite;

            return $options;
        });

        try {
            // $result is null when the plugin is already installed.
            $result = $upgrader->install($downloadLink, [
                'overwrite_package' => $overwrite,
            ]);

            $data = $upgrader->new_plugin_data;

            if (is_null($result) && !$overwrite) {
                $result = new \WP_Error('plugin_already_installed', 'The plugin is already installed.');
            } else if (empty($data)) {
                $result = new \WP_Error('no_plugin_installed', 'No plugin installed.');
            }

            if (is_wp_error($result)) {
                return $this->parseActionResponse($downloadLink, $result, 'install', ManagerPlugin::PLUGINS);
            }

            // We cannot use $this->all() because this function remaps the plugins.
            $allPlugins = get_plugins();

            $results = [];

            // Some sites may have the same plugin installed with different versions or paths.
            foreach ($allPlugins as $key => $value) {
                if ($value['Name'] === $data['Name'] &&
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

            $this->checkForUpdates();

            $updatablePlugins = $this->getItemsToUpdate(static::PLUGINS);
            $data = $this->map('plugin', Collection::make([$basename => $data]), $updatablePlugins);

            return $this->parseActionResponse($basename, $data[array_key_first($data)], 'install', ManagerPlugin::PLUGINS);
        } catch (\Throwable $e) {
            return $this->parseActionResponse($downloadLink, $e, 'install', ManagerPlugin::PLUGINS);
        }
    }

    /**
     * @param \stdClass $items
     * @return mixed
     * @throws \Exception
     */
    public function activate(\stdClass $items)
    {
        $this->include();

        $response = [];

        foreach ($items as $plugin => $args) {
            $silent = $args->silent ?? false;
            $networkWide = is_bool($args->network_wide) ? $args->network_wide : is_plugin_active_for_network($plugin);

            try {
                $result = activate_plugin(
                    $plugin,
                    '',
                    $networkWide,
                    $silent
                );

                $response[$plugin] = [
                    'status' => !is_wp_error($result) && is_plugin_active($plugin) ? 'success' : 'error',
                ];
            } catch (\Throwable $e) {
                $response[$plugin] = $e;
            }
        }

        return $this->parseBulkActionResponse(array_keys(get_object_vars($items)), $response, 'activate', ManagerPlugin::PLUGINS);
    }

    /**
     * @param \stdClass $items
     * @return mixed
     * @throws \Exception
     */
    public function deactivate(\stdClass $items)
    {
        $this->include();

        $response = [];

        foreach ($items as $plugin => $args) {
            $silent = $args->silent ?? false;
            $networkWide = is_bool($args->network_wide) ? $args->network_wide : is_plugin_active_for_network($plugin);

            try {
                deactivate_plugins($plugin, $silent, $networkWide);

                $response[$plugin] = [
                    'status' => is_plugin_inactive($plugin) ? 'success' : 'error',
                ];
            } catch (\Throwable $e) {
                $response[$plugin] = $e;
            }
        }

        return $this->parseBulkActionResponse(array_keys(get_object_vars($items)), $response, 'deactivate', ManagerPlugin::PLUGINS);
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
        $this->includeUpgrader();

        $skin = new \WP_Ajax_Upgrader_Skin();
        $upgrader = new \Plugin_Upgrader($skin);

        $response = @$upgrader->bulk_upgrade($items);

        $this->checkForUpdates();

        return $this->parseBulkActionResponse($items, $response, 'upgrade', ManagerPlugin::PLUGINS);
    }

    /**
     * @param array $items
     * @return array
     * @throws \Exception
     */
    public function delete(array $items)
    {
        $this->include();

        $response = [];
        $basenamesToDelete = [];

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

        return $this->parseBulkActionResponse($items, $response, 'delete', ManagerPlugin::PLUGINS);
    }
}
