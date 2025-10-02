<?php

namespace Modular\Connector\Providers;

use Modular\Connector\Backups\BackupManager;
use Modular\Connector\Facades\Manager as ManagerFacade;
use Modular\Connector\Facades\WhiteLabel;
use Modular\Connector\Services\Manager;
use Modular\Connector\Services\Manager\ManagerSafeUpgrade;
use Modular\Connector\Services\Manager\ManagerWooCommerce;
use Modular\Connector\Services\ManagerServer;
use Modular\Connector\Services\ManagerWhiteLabel;
use Modular\Connector\Services\ServiceDatabase;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Auth\JWT;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Cache;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Config;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Queue;
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
        $this->app->singleton(ManagerSafeUpgrade::class, fn() => new ManagerSafeUpgrade());
        $this->app->singleton(ServiceDatabase::class, fn() => new ServiceDatabase());
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

            $hook = $this->app->getScheduleHook();
            $url = apply_filters(sprintf('%s_query_url', $hook), site_url('wp-load.php'));

            $query = apply_filters(
                sprintf('%s_query_args', $hook),
                [
                    'origin' => 'mo',
                    'type' => 'lb',
                    'nonce' => wp_create_nonce($hook),
                ]
            );

            $url = add_query_arg($query, $url);

            $args = [
                'timeout' => 10, // In some websites, the default value of 5 seconds is too short.
                'sslverify' => false,
                'blocking' => $debugSchedule,
                'headers' => [],
            ];

            try {
                $token = JWT::generate($hook);
                $args['headers']['Authentication'] = 'Bearer ' . $token;
            } catch (\Throwable $e) {
                // Silence is golden
                Log::debug($e);
            }

            if ($authorization = Cache::driver('wordpress')->get('header.authorization')) {
                $args['headers']['Authorization'] = $authorization;
            }

            $args = apply_filters(sprintf('%s_post_args', $hook), $args);
            $response = wp_remote_get(esc_url_raw($url), $args);

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
        $this->registerActionLinks();
        $this->registerForceCallSchedule();
    }

    /**
     * @return void
     */
    public function boot()
    {
        $this->booted(function () {
            try {
                // Try to migrate the database in separate process, because in some hosts it executed right but throws an error
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
                    Cache::driver($driver)->has('test');
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
                    Queue::connection($driver)->size('default');
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
}
