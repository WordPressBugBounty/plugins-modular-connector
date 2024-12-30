<?php

namespace Modular\Connector\Services\Manager;

use Modular\Connector\Facades\Manager;
use Modular\Connector\Facades\Server;
use Modular\ConnectorDependencies\Illuminate\Support\Collection;

/**
 * Handles all functionality related to WordPress Themes.
 */
class ManagerTheme extends AbstractManager
{
    public const THEMES = 'themes';

    /**
     * @return string
     */
    public function getActive()
    {
        return wp_get_theme()->get_template();
    }

    /**
     * Returns a list with the installed themes in the webpage, including the new version if available.
     *
     * @return array
     */
    public function all()
    {
        $updatableThemes = $this->getItemsToUpdate(ManagerTheme::THEMES);
        $installedThemes = Collection::make(wp_get_themes());

        return $this->map('theme', $installedThemes, $updatableThemes);
    }

    public function install(string $downloadLink, bool $overwrite = true)
    {
        Manager::includeUpgrader();
        Manager::clean();

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
                return $this->parseActionResponse($downloadLink, $result, 'install', ManagerTheme::THEMES);
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

            return $this->parseActionResponse(is_array($data) && isset($data['basename']) ? $data['basename'] : $downloadLink, $data, 'install', ManagerTheme::THEMES);
        } catch (\Throwable $e) {
            return $this->parseActionResponse($downloadLink, $e, 'install', ManagerTheme::THEMES);
        } finally {
            Server::logout();
        }
    }

    /**
     * @param \stdClass $theme
     * @return array
     * @throws \Exception
     */
    public function activate(\stdClass $theme)
    {
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

        return $this->parseBulkActionResponse($items, $response, 'activate', ManagerTheme::THEMES);
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
        add_filter('auto_update_theme', '__return_false', PHP_INT_MAX);

        Manager::clean();
        Manager::includeUpgrader();

        try {
            $skin = new \WP_Ajax_Upgrader_Skin();
            $upgrader = new \Theme_Upgrader($skin);

            $response = @$upgrader->bulk_upgrade($themes);
        } finally {
            Manager::clean();
            Server::logout();
        }

        return $this->parseBulkActionResponse($themes, $response, 'upgrade', ManagerTheme::THEMES);
    }

    /**
     * @param \stdClass $items
     * @return array
     * @throws \Exception
     */
    public function delete(array $items)
    {
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

        return $this->parseBulkActionResponse($items, $response, 'delete', ManagerTheme::THEMES);
    }
}
