<?php

namespace Modular\Connector\Services\Manager;

use Modular\ConnectorDependencies\Ares\Framework\Foundation\ScreenSimulation;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\ServerSetup;
use Modular\ConnectorDependencies\Illuminate\Support\Collection;

/**
 * Handles all functionality related to WordPress Core.
 */
class ManagerCore extends AbstractManager
{
    /**
     * @return string
     */
    private function locale()
    {
        ScreenSimulation::includeUpgrader();

        $locale = @get_locale();

        return $locale ?: ($GLOBALS['wp_local_package'] ?? null);
    }

    /**
     * Read the version from wp-includes/version.php in a local scope: some
     * hosts (e.g. GridPane) fake global $wp_version on init to hide it.
     *
     * @return string|null
     */
    private function version()
    {
        ScreenSimulation::includeUpgrader();

        if (function_exists('wp_get_wp_version')) {
            return wp_get_wp_version();
        }

        $versionFile = ABSPATH . WPINC . '/version.php';

        if (is_readable($versionFile)) {
            $wp_version = null;

            // require (not _once): the file only assigns variables, and it must
            // run again here so $wp_version lands in this scope.
            require $versionFile;

            if (!empty($wp_version)) {
                return $wp_version;
            }
        }

        return $GLOBALS['wp_version'] ?? null;
    }

    /**
     * Returns the current WordPress version and the available updates.
     *
     * @return array
     */
    public function get()
    {
        ScreenSimulation::includeUpgrader();
        ServerSetup::refreshCoreUpdates();

        $coreUpdate = $this->getLatestUpdate();
        $newVersion = $coreUpdate->version ?? null;
        $newVersionLocale = $coreUpdate->locale ?? null;

        return [
            'basename' => 'core',
            'name' => 'WordPress',
            'locale' => $this->locale(),
            'version' => $this->version(),
            'new_version' => $newVersion,
            'new_version_locale' => $newVersionLocale,
            'requires_php' => $GLOBALS['required_php_version'] ?? null,
            'mysql_version' => $GLOBALS['required_mysql_version'] ?? null,
            'status' => 'active',
        ];
    }

    /**
     * Finds the available update for WordPress core.
     *
     * @return object|false The core update offering on success, false on failure.
     */
    private function getLatestUpdate()
    {
        ScreenSimulation::includeUpgrader();

        $checker = get_site_transient('update_core');

        if (!isset($checker->updates) || !is_array($checker->updates)) {
            return null;
        }

        return Collection::make($checker->updates)
            ->sortByDesc('version')
            ->filter(fn($update) => !in_array($update->response, ['latest', 'development']))
            ->first();
    }

    /**
     * Upgrades the WordPress core to the latest available version.
     *
     * @return bool|array
     * @throws \Exception
     */
    public function upgrade($items = [])
    {
        ScreenSimulation::includeUpgrader();

        $skin = new \WP_Ajax_Upgrader_Skin();
        $core = new \Core_Upgrader($skin);

        ServerSetup::restoreUpdateTransient('core');

        $result = @$core->upgrade($this->getLatestUpdate());

        return $this->parseActionResponse('core', $result, 'upgrade', 'core');

    }
}
