<?php

namespace Modular\Connector\Backups;

use Modular\Connector\Backups\Adapters\BackupIronDriver;
use Modular\Connector\Backups\Phantom\BackupDriverPhantomDriver;
use Modular\Connector\Facades\Manager as ModularManager;
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
        return Cache::get('backup.driver') ?: $this->config->get('backup.default');
    }

    /**
     * Get a driver instance.
     *
     * @param string|null $driver
     * @return mixed
     *
     * @throws \InvalidArgumentException
     */
    public function driver($driver = null)
    {
        $driver = 'phantom';
        
        return parent::driver($driver);
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
        return Storage::disk('backup')->path(untrailingslashit($path));
    }

    /**
     * Return core WordPress dir
     *
     * @return string
     */
    public function getCoreDir()
    {
        return Storage::disk('root')->path('');
    }

    /**
     * Return plugin WordPress dir
     *
     * @return string
     */
    public function getPluginsDir()
    {
        return Storage::disk('plugin')->path('');
    }

    /**
     * Return theme WordPress dir
     *
     * @return string
     */
    public function getThemesDir()
    {
        return Storage::disk('theme')->path('');
    }

    /**
     * Return upload WordPress dir
     *
     * @return string
     */
    public function getUploadsDir()
    {
        return Storage::disk('upload')->path('');
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
        if (!Storage::disk('backup')->exists('index.html')) {
            Storage::disk('backup')->put('index.html', '<!-- // Silence is golden. -->');
        }

        if (!Storage::disk('backup')->exists('index.php')) {
            Storage::disk('backup')->put('index.php', '<?php // Silence is golden.');
        }

        if (!Storage::disk('backup')->exists('.htaccess')) {
            Storage::disk('backup')->put('.htaccess', 'deny from all');
        }

        if (!Storage::disk('backup')->exists('web.config')) {
            $webConfig = '<configuration>';
            $webConfig .= '<system.webServer>';
            $webConfig .= '<authorization>';
            $webConfig .= '<deny users="*" />';
            $webConfig .= '</authorization>';
            $webConfig .= '</system.webServer>';
            $webConfig .= '</configuration>';

            Storage::disk('backup')->put('web.config', $webConfig);
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
            'plugins' => Collection::make(ModularManager::driver('plugin')->all())
                ->map(function ($item) {
                    return [
                        'name' => $item['name'],
                        'basename' => $item['basename'],
                        'version' => $item['version'],
                        'status' => $item['status'],
                    ];
                }),
            'themes' => Collection::make(ModularManager::driver('theme')->all())
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
}
