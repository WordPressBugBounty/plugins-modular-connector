<?php

namespace Modular\Connector\Services\Manager;

use Modular\ConnectorDependencies\Ares\Framework\Foundation\ScreenSimulation;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\ServerSetup;
use Modular\ConnectorDependencies\Illuminate\Support\Collection;

/**
 */
class ManagerTheme extends AbstractManager
{
    /**
     * @return string
     */
    public function getActive()
    {
        ScreenSimulation::includeUpgrader();

        return wp_get_theme()->get_template();
    }

    /**
     * Returns a list with the installed themes in the webpage, including the new version if available.
     *
     * @return array
     */
    public function all(bool $checkUpdates = true)
    {
        ScreenSimulation::includeUpgrader();

        if (!function_exists('wp_get_themes')) {
            require_once ABSPATH . 'wp-admin/includes/theme.php';
        }

        if (!function_exists('get_theme_updates')) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }

        $updatableThemes = $checkUpdates ? $this->getItemsToUpdate(self::THEME) : [];
        $installedThemes = Collection::make(wp_get_themes());

        return $this->map(self::THEME, $installedThemes, $updatableThemes);
    }

    public function install(string $downloadLink, bool $overwrite = true)
    {
        ScreenSimulation::includeUpgrader();

        ServerSetup::clean();

        add_filter('upgrader_package_options', function ($options) use ($overwrite) {
            $options['clear_destination'] = $overwrite;

            return $options;
        });

        try {
            $skin = new \WP_Ajax_Upgrader_Skin();
            $skin->api = null;

            $upgrader = new \Theme_Upgrader($skin);

            $result = $upgrader->install($downloadLink, [
                'overwrite_package' => $overwrite,
            ]);

            $data = $upgrader->new_theme_data;

            if (empty($data) || $result === false || is_null($result)) {
                $result = new \WP_Error('no_theme_installed', 'No theme installed.');
            }

            if (is_wp_error($result)) {
                return $this->parseActionResponse($downloadLink, $result, 'install', self::THEME);
            }

            $allThemes = $this->all();

            $results = array_values(array_filter($allThemes, fn($theme) => $theme['name'] === $data['Name']));

            if (count($results) > 1) {
                // Sort the themes by the most recent modification date.
                usort(
                    $results,
                    fn($a, $b) => filemtime(get_theme_root() . DIRECTORY_SEPARATOR . $b['basename']) - filemtime(get_theme_root() . DIRECTORY_SEPARATOR . $a['basename'])
                );
            }

            $data = $results[0] ?? null;

            return $this->parseActionResponse(is_array($data) && isset($data['basename']) ? $data['basename'] : $downloadLink, $data, 'install', self::THEME);
        } catch (\Throwable $e) {
            return $this->parseActionResponse($downloadLink, $e, 'install', self::THEME);
        } finally {
            ServerSetup::logout();
        }
    }

    /**
     * @param \stdClass $theme
     * @return array
     * @throws \Exception
     */
    public function activate(\stdClass $theme)
    {
        ScreenSimulation::includeUpgrader();

        $items = array_keys(get_object_vars($theme));

        $basename = $items[0];

        $response = [];

        try {
            switch_theme($basename);

            $result = $basename === $this->getActive() ? 'success' : 'error';

            $response[$basename] = [
                'status' => $result,
            ];
        } catch (\Throwable $e) {
            $response[$basename] = $e;
        }

        return $this->parseBulkActionResponse($items, $response, 'activate', self::THEME);
    }

    /**
     * Makes a bulk upgrade of the provided $themes to the most recent version. Returns a list of plugins basenames
     * and a 'true' value if they are in the most recent version.
     *
     * @param array $themes
     * @return array[]|false
     * @throws \Exception
     */
    public function upgrade(array $themes = [])
    {
        ScreenSimulation::includeUpgrader();
        ServerSetup::clean();

        try {
            $skin = new \WP_Ajax_Upgrader_Skin();
            $upgrader = new \Theme_Upgrader($skin);

            $response = @$upgrader->bulk_upgrade($themes);
        } finally {
            ServerSetup::clean();
            ServerSetup::logout();
        }

        return $this->parseBulkActionResponse($themes, $response, 'upgrade', self::THEME);
    }

    /**
     * @param \stdClass $items
     * @return array
     * @throws \Exception
     */
    public function delete(array $items)
    {
        ScreenSimulation::includeUpgrader();

        $response = [];
        $basenamesToDelete = [];

        foreach ($items as $theme) {
            if ($theme === $this->getActive()) {
                $response[$theme] = new \WP_Error('theme_active', 'The theme is currently active.');
            } else {
                $basenamesToDelete[] = $theme;
            }
        }

        foreach ($basenamesToDelete as $basename) {
            try {
                $result = delete_theme($basename);
                $result = $result === true ? 'success' : (is_wp_error($result) ? $result : 'error');
            } catch (\Throwable $e) {
                $result = $e;
            }

            $response[$basename] = [
                'status' => $result,
            ];
        }

        return $this->parseBulkActionResponse($items, $response, 'delete', self::THEME);
    }
}
