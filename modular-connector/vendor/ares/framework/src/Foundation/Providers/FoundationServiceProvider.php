<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Providers;

use Modular\ConnectorDependencies\Ares\Framework\Foundation\Auth\JWT;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Http\HttpUtils;
use Modular\ConnectorDependencies\Illuminate\Console\Scheduling\Schedule;
use Modular\ConnectorDependencies\Illuminate\Contracts\Http\Kernel;
use Modular\ConnectorDependencies\Illuminate\Support\Collection;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
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
            $queues = Collection::make($this->app->make('config')->get('queue.names', []));
            $debugSchedule = $this->app->make('config')->get('app.debug_schedule', \false);
            // We force the schedule run only if there are pending jobs in the queues or if it's a direct request.
            $hasPendingJobs = HttpUtils::isDirectRequest() || $queues->some(function ($queue) use ($debugSchedule) {
                $size = $this->app->make('queue')->size($queue);
                if ($debugSchedule) {
                    Log::debug('Checking queue', ['queue' => $queue, 'size' => $size]);
                }
                return $size > 0;
            });
            if (!$hasPendingJobs) {
                return;
            }
            $hook = $this->app->getScheduleHook();
            $url = apply_filters(sprintf('%s_query_url', $hook), admin_url('admin-ajax.php'));
            $query = apply_filters(sprintf('%s_query_args', $hook), ['action' => $hook, 'nonce' => wp_create_nonce($hook)]);
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
