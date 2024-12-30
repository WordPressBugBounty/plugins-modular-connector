<?php

namespace Modular\Connector\Providers;

use Modular\Connector\Backups\BackupManager;
use Modular\Connector\Facades\WhiteLabel;
use Modular\Connector\Services\Manager;
use Modular\Connector\Services\Manager\ManagerWooCommerce;
use Modular\Connector\Services\ManagerServer;
use Modular\Connector\Services\ManagerWhiteLabel;
use Modular\ConnectorDependencies\Illuminate\Support\ServiceProvider;
use function Modular\ConnectorDependencies\base_path;

class ModularConnectorServiceProvider extends ServiceProvider
{
    /**
     * @return void
     */
    protected function registerFacades()
    {
        $this->app->bind(BackupManager::class, fn($app) => new BackupManager($app));

        $this->app->singleton(Manager::class, fn($app) => new Manager($app));
        $this->app->singleton(ManagerServer::class, fn() => new ManagerServer());
        $this->app->singleton(ManagerWhiteLabel::class, fn() => new ManagerWhiteLabel());
        $this->app->singleton(ManagerWooCommerce::class, fn() => new ManagerWooCommerce());
    }

    /**
     * Register action links.
     *
     * @return void
     */
    public function registerActionLinks()
    {
        add_filter('plugin_action_links', function ($links = null, $plugin = null) {
            // if you use this action hook inside main plugin file, use basename(__FILE__) to check
            $path = str_replace('\\', '/', realpath(base_path('../init.php')));
            $path = preg_replace('|(?<=.)/+|', '/', $path);

            $plugin = str_replace('\\', '/', $plugin);
            $plugin = preg_replace('|(?<=.)/+|', '/', $plugin);

            if (strpos($path, $plugin)) {
                $links[] = sprintf('<a href="%s">%s</a>', menu_page_url('modular-connector', false), __('Connection manager', 'modular-connector'));
            }

            return $links;
        }, 10, 2);
    }

    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->registerFacades();
        $this->registerActionLinks();

        WhiteLabel::init();
    }
}
