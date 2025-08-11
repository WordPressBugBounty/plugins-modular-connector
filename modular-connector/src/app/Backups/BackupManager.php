<?php

namespace Modular\Connector\Backups;

use Modular\Connector\Backups\Iron\BackupIronDriver;
use Modular\Connector\Backups\Phantom\BackupDriverPhantomDriver;
use Modular\Connector\Facades\Manager as ModularManager;
use Modular\Connector\Services\Manager\ManagerPlugin;
use Modular\Connector\Services\Manager\ManagerTheme;
use Modular\ConnectorDependencies\Carbon\Carbon;
use Modular\ConnectorDependencies\Illuminate\Support\Collection;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Cache;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Storage;
use Modular\ConnectorDependencies\Illuminate\Support\Manager;

class BackupManager extends Manager
{
    /**
     * @return mixed|string
     */
    public function getDefaultDriver()
    {
        return Cache::driver('wordpress')->get('backup.driver') ?: $this->config->get('backup.default');
    }

    /**
     * @return BackupDriverPhantomDriver
     */
    public function createPhantomDriver()
    {
        return new BackupDriverPhantomDriver();
    }

    /**
     * @return BackupIronDriver
     */
    public function createIronDriver()
    {
        return new BackupIronDriver();
    }

    /**
     * Get relative backups path
     *
     * @param string|null $path
     * @return string
     */
    public function path(?string $path = null)
    {
        return Storage::disk('backups')->path(untrailingslashit($path));
    }

    /**
     * Makes the initializations needed to let the WordPress work.
     *
     * It creates a dedicated folder to backups, with an empty 'index.html' file inside, and a '.htaccess' file ('deny
     * from all') also inside it.
     *
     * This method must be called when the plugin is installed.
     *
     * @return void
     */
    public function init(): void
    {
        if (!Storage::disk('backups')->exists('index.html')) {
            Storage::disk('backups')->put('index.html', '<!-- // Silence is golden. -->');
        }

        if (!Storage::disk('backups')->exists('index.php')) {
            Storage::disk('backups')->put('index.php', '<?php // Silence is golden.');
        }

        if (!Storage::disk('backups')->exists('.htaccess')) {
            Storage::disk('backups')->put('.htaccess', 'deny from all');
        }

        if (!Storage::disk('backups')->exists('web.config')) {
            $webConfig = '<configuration>';
            $webConfig .= '<system.webServer>';
            $webConfig .= '<authorization>';
            $webConfig .= '<deny users="*" />';
            $webConfig .= '</authorization>';
            $webConfig .= '</system.webServer>';
            $webConfig .= '</configuration>';

            Storage::disk('backups')->put('web.config', $webConfig);
        }
    }

    /**
     * Makes a backup in the Modular backups folder that includes the provided $options (if valid) as sub folders. Valid
     * options are: 'plugins', 'themes', 'uploads', 'others', 'mu_plugins', 'wp_core' and 'database
     *
     * We assume options come not empty and valid.
     *
     * This process changes the current backups status and also the specific backup status with the 3 steps: 'trying to
     * create zip', 'error creating zip' and 'zip successfully created'.
     *
     * @return array
     * @throws \Exception
     */
    public function information()
    {
        return [
            'posts' => wp_count_posts(),
            'attachment' => wp_count_posts('attachment'),
            'core' => ModularManager::driver('core')->get(),
            'plugins' => Collection::make(ModularManager::driver(ManagerPlugin::PLUGIN)->all(false))
                ->map(function ($item) {
                    return [
                        'name' => $item['name'],
                        'basename' => $item['basename'],
                        'version' => $item['version'],
                        'status' => $item['status'],
                    ];
                }),
            'themes' => Collection::make(ModularManager::driver(ManagerTheme::THEME)->all(false))
                ->map(function ($item) {
                    return [
                        'name' => $item['name'],
                        'basename' => $item['basename'],
                        'version' => $item['version'],
                        'status' => $item['status'],
                    ];
                }),
            'database' => ModularManager::driver('database')->get(),
        ];
    }

    /**
     * @param string|null $name
     * @return void
     */
    public function cancel(?string $name)
    {
        if (!$name) {
            return;
        }

        $value = Cache::get('_cancelled_backup', []);
        $value[] = $name;

        Cache::put('_cancelled_backup', array_unique($value), Carbon::now()->addDay());
    }
}
