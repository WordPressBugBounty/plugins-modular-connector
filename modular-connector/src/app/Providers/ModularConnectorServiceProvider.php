<?php

namespace Modular\Connector\Providers;

use Modular\Connector\Backups\BackupManager;
use Modular\Connector\Backups\LocalDriver;
use Modular\Connector\BrokenLinks\BrokenLinkManager;
use Modular\Connector\Facades\Manager as ManagerFacade;
use Modular\Connector\Facades\WhiteLabel;
use Modular\Connector\Helper\Loopback;
use Modular\Connector\Helper\OauthClient;
use Modular\Connector\Http\ModularRouteResolver;
use Modular\Connector\Jobs\ConfigureDriversJob;
use Modular\Connector\Security\ScanManager;
use Modular\Connector\Services\Manager;
use Modular\Connector\Services\Manager\ManagerDatabase;
use Modular\Connector\Services\Manager\ManagerSafeUpgrade;
use Modular\Connector\Services\Manager\ManagerWooCommerce;
use Modular\Connector\Services\ManagerServer;
use Modular\Connector\Services\ManagerWhiteLabel;
use Modular\Connector\Services\ServiceDatabase;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Contracts\RouteResolver;
use Modular\ConnectorDependencies\Illuminate\Filesystem\FilesystemAdapter;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Cache;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Config;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Queue;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Storage;
use Modular\ConnectorDependencies\Illuminate\Support\ServiceProvider;
use Modular\ConnectorDependencies\League\Flysystem\Adapter\Local as LocalAdapter;
use Modular\ConnectorDependencies\League\Flysystem\Filesystem as Flysystem;
use Modular\SDK\ModularClientInterface;
use Modular\SDK\Objects\SiteRequest;
use function Modular\ConnectorDependencies\base_path;

class ModularConnectorServiceProvider extends ServiceProvider
{
    /**
     * @return void
     */
    protected function registerFacades()
    {
        $this->app->bind(BackupManager::class, fn($app) => new BackupManager($app));
        $this->app->singleton(ScanManager::class, fn($app) => new ScanManager($app));
        $this->app->singleton(BrokenLinkManager::class, fn($app) => new BrokenLinkManager($app));

        $this->app->singleton(Manager::class, fn($app) => new Manager($app));
        $this->app->singleton(ManagerServer::class, fn() => new ManagerServer());
        $this->app->singleton(ManagerWhiteLabel::class, fn() => new ManagerWhiteLabel());
        $this->app->singleton(ManagerWooCommerce::class, fn() => new ManagerWooCommerce());
        $this->app->singleton(ManagerSafeUpgrade::class, fn() => new ManagerSafeUpgrade());
        $this->app->singleton(ServiceDatabase::class, fn() => new ServiceDatabase());

        // Register Modular SDK client singleton
        $this->app->singleton(ModularClientInterface::class, fn() => OauthClient::getClient());

        // Register route resolver for secure internal routing
        $this->app->singleton(RouteResolver::class, fn() => new ModularRouteResolver());
    }

    /**
     * Register action links.
     *
     * @return void
     */
    public function registerActionLinks()
    {
        add_filter('plugin_action_links', function ($links = null, $plugin = null) {
            $isEnabled = WhiteLabel::isEnabled();

            if ($isEnabled) {
                return $links;
            }

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
     * Register the custom local filesystem driver.
     *
     * @return void
     */
    protected function registerFilesystemDriver()
    {
        Storage::extend('local', function ($app, $config) {
            $permissions = $config['permissions'] ?? [];

            $links = ($config['links'] ?? null) === 'skip'
                ? LocalAdapter::SKIP_LINKS
                : LocalAdapter::DISALLOW_LINKS;

            // Use our custom LocalDriver instead of the standard LocalAdapter
            $adapter = new LocalDriver(
                $config['root'],
                $config['lock'] ?? LOCK_EX,
                $links,
                $permissions
            );

            return new FilesystemAdapter(
                new Flysystem($adapter, $config ?? [])
            );
        });
    }

    /**
     * Many sites have problems with the WP Cron system, so we need to force the schedule run.
     * This method will be called when the application is terminating and will force
     * the schedule run by calling an AJAX action.
     *
     * @return void
     */
    public function registerForceCallSchedule()
    {
        $this->app->afterTerminating(function () {
            // If the loopback is disabled, we don't need to force the schedule run.
            if (!$this->app->make('config')->get('app.loopback')) {
                Log::debug('Loopback is disabled, skipping force dispatch schedule run.');

                return;
            }

            $forceDispatch = $this->app->forceDispatchScheduleRun || Cache::driver('array')->get('ares.forceDispatchScheduleRun', false);

            $dontForceDispatch = Cache::driver('array')->get('ares.dontDispatchScheduleRun', false);

            if (!$forceDispatch || $dontForceDispatch) {
                return;
            }

            $debugSchedule = $this->app->make('config')->get('app.debug_schedule', false);

            $lbNonce = Loopback::nonce();
            $url = Loopback::url($lbNonce);

            try {
                $args = Loopback::args($lbNonce, [], $debugSchedule);
            } catch (\Throwable $e) {
                Log::debug('Loopback: Failed to generate JWT', ['error' => $e->getMessage()]);

                return;
            }

            if (is_null($args)) {
                Log::warning('Loopback: client_secret or client_id not available, skipping dispatch');

                return;
            }

            $response = wp_remote_get($url, $args);

            if ($debugSchedule) {
                $context = [
                    'url' => $url,
                    'args' => $args,
                    'response' => $response,
                    'request' => $this->app->make('request')->all(),
                ];

                $this->app->make('log')
                    ->debug('Force dispatch queue', $context);
            } else {
                unset($response);
            }
        });
    }

    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->registerFacades();
        $this->registerFilesystemDriver();
        $this->registerActionLinks();
        $this->registerForceCallSchedule();
        $this->registerSiteRequestBinding();
    }

    /**
     * Register SiteRequest binding as null to resolve from request attributes.
     *
     * @return void
     */
    protected function registerSiteRequestBinding()
    {
        $this->app->bind(SiteRequest::class, fn($app) => null);
    }

    /**
     * @return void
     */
    public function boot()
    {
        $this->booted(function () {
            try {
                ManagerFacade::driver('database')->migrate();
            } catch (\Throwable $e) {
                // Silence is golden
                Log::error($e);
            }

            try {
                if (defined('MODULAR_CONNECTOR_CACHE_DRIVER')) {
                    $driver = MODULAR_CONNECTOR_CACHE_DRIVER;
                } elseif (Cache::driver('wordpress')->has('cache.default')) {
                    $driver = Cache::driver('wordpress')->get('cache.default') ?: Config::get('cache.default');
                } else {
                    $driver = Config::get('cache.default');
                }

                if ($driver !== 'file') {
                    $this->probeDriver(fn() => ConfigureDriversJob::probeCache($driver));
                }

                Config::set('cache.default', $driver);
            } catch (\Throwable $e) {
                // Silence is golden
            }

            try {
                if (defined('MODULAR_CONNECTOR_QUEUE_DRIVER')) {
                    $driver = MODULAR_CONNECTOR_QUEUE_DRIVER;
                } elseif (Cache::driver('wordpress')->has('queue.default')) {
                    $driver = Cache::driver('wordpress')->get('queue.default') ?: Config::get('queue.default');
                } else {
                    $driver = Config::get('queue.default');
                }

                if ($driver !== 'wordpress') {
                    $this->probeDriver(fn() => ConfigureDriversJob::probeQueue($driver));
                }

                Config::set('queue.default', $driver);
            } catch (\Throwable $e) {
                // Silence is golden
            }
        });

        $this->booted(function () {
            WhiteLabel::init();
        });
    }

    /**
     * Run a driver probe, recreating the plugin tables once when one is missing.
     *
     * A table that vanished after its migration was recorded is never recreated by
     * migrate(), so the probe is where the site gets its schema back. Anything else, or a
     * repair that does not bring the table back, keeps the probe's failure so the caller
     * falls through to the file/wordpress driver.
     *
     * @param \Closure $probe
     * @return void
     * @throws \Throwable
     */
    private function probeDriver(\Closure $probe): void
    {
        try {
            $probe();
        } catch (\Throwable $e) {
            if (!ManagerDatabase::isMissingTableException($e) || !ManagerFacade::driver('database')->repair()) {
                throw $e;
            }

            $probe();
        }
    }
}
