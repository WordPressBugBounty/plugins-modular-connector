<?php

namespace Modular\Connector\Services\Manager;

use Modular\Connector\Facades\Manager;
use Modular\Connector\Facades\Server;
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
        $locale = @get_locale();

        return $locale ?: ($GLOBALS['wp_local_package'] ?? null);
    }

    /**
     * @return string
     */
    private function version()
    {
        return $GLOBALS['wp_version'] ?? null;
    }

    /**
     * Returns the current WordPress version and the available updates.
     *
     * @return array
     */
    public function get()
    {
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
        // Allow core updates
        add_filter('auto_update_core', '__return_true', PHP_INT_MAX);
        add_filter('allow_major_auto_core_updates', '__return_true', PHP_INT_MAX);
        add_filter('allow_minor_auto_core_updates', '__return_true', PHP_INT_MAX);
        add_filter('auto_core_update_send_email', '__return_false', PHP_INT_MAX);

        Manager::includeUpgrader();
        Manager::clean();

        try {
            $skin = new \WP_Ajax_Upgrader_Skin();
            $core = new \Core_Upgrader($skin);

            $result = @$core->upgrade($this->getLatestUpdate());
        } finally {
            Manager::clean();
            Server::logout();
        }

        return $this->parseActionResponse('core', $result, 'upgrade', 'core');

    }
}
