<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Providers;

use Modular\ConnectorDependencies\Ares\Framework\Foundation\Auth\JWT;
use Modular\ConnectorDependencies\Illuminate\Console\Scheduling\Schedule;
use Modular\ConnectorDependencies\Illuminate\Contracts\Http\Kernel;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Cache;
use Modular\ConnectorDependencies\Illuminate\Support\ServiceProvider;
class FoundationServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        parent::register();
        $this->registerConsoleSchedule();
        $this->registerForceCallSchedule();
    }
    /**
     * Register the console schedule implementation.
     *
     * @return void
     */
    public function registerConsoleSchedule()
    {
        $this->app->singleton(Schedule::class, function ($app) {
            return $app->make(Kernel::class)->resolveConsoleSchedule();
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
        $this->app->terminating(function () {
            $forceDispatch = $this->app->forceDispatchScheduleRun || Cache::driver('array')->get('ares.forceDispatchScheduleRun', \false);
            $dontForceDispatch = Cache::driver('array')->get('ares.dontDispatchScheduleRun', \false);
            if (!$forceDispatch || $dontForceDispatch) {
                return;
            }
            $debugSchedule = $this->app->make('config')->get('app.debug_schedule', \false);
            $hook = $this->app->getScheduleHook();
            $url = apply_filters(sprintf('%s_query_url', $hook), site_url('wp-load.php'));
            $query = apply_filters(sprintf('%s_query_args', $hook), ['origin' => 'mo', 'type' => 'lb', 'nonce' => wp_create_nonce($hook)]);
            $url = add_query_arg($query, $url);
            $args = [
                'timeout' => 10,
                // In some websites, the default value of 5 seconds is too short.
                'sslverify' => \false,
                'blocking' => $debugSchedule,
            ];
            try {
                $token = JWT::generate($hook);
                $args['headers'] = ['Authentication' => 'Bearer ' . $token];
            } catch (\Throwable $e) {
                // Silence is golden
            }
            $args = apply_filters(sprintf('%s_post_args', $hook), $args);
            $response = wp_remote_get(esc_url_raw($url), $args);
            if ($debugSchedule) {
                $context = ['url' => $url, 'args' => $args, 'response' => $response, 'request' => $this->app->make('request')->all()];
                $this->app->make('log')->debug('Force dispatch queue', $context);
            } else {
                unset($response);
            }
        });
    }
}
