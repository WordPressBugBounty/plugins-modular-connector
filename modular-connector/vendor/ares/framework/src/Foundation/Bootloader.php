<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation;

use Modular\ConnectorDependencies\Ares\Framework\Foundation\Http\HttpUtils;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Http\Pipeline\AuthenticateRequest;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Http\Pipeline\BeforeLogin;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Http\Pipeline\FetchModularRequest;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Http\Pipeline\ForceCompatibilities;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Http\Pipeline\ForceCronCompatibilities;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Http\Pipeline\SetupAdminEnvironment;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Http\Pipeline\ValidateRoute;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Routing\Router;
use Modular\ConnectorDependencies\Illuminate\Contracts\Foundation\Application as ApplicationContract;
use Modular\ConnectorDependencies\Illuminate\Contracts\Http\Kernel as IlluminateHttpKernel;
use Modular\ConnectorDependencies\Illuminate\Http\Request;
use Modular\ConnectorDependencies\Illuminate\Http\Response;
use Modular\ConnectorDependencies\Illuminate\Pipeline\Pipeline;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Cache;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Facade;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
/**
 * Class Bootloader inspired by Roots (Acorn).
 *
 * @link https://github.com/roots/acorn/blob/4.x/src/Roots/Acorn/Bootloader.php
 */
class Bootloader
{
    /**
     * The Bootloader instance.
     */
    protected static $instance;
    /**
     * @var ApplicationContract|null
     */
    protected ?ApplicationContract $app;
    /**
     * @var string
     */
    protected string $basePath;
    /**
     * @param 1ApplicationContract|null $app
     */
    public function __construct(?ApplicationContract $app = null)
    {
        $this->app = $app;
    }
    /**
     * Get the Bootloader instance.
     */
    public static function getInstance(?ApplicationContract $app = null): self
    {
        return static::$instance ??= new static($app);
    }
    /**
     * Initialize and retrieve the Application instance.
     */
    public function getApplication(): ApplicationContract
    {
        $this->app ??= new Application($this->basePath);
        $this->app->singleton('router', Router::class);
        return $this->app;
    }
    /**
     * @return void
     */
    public function configRequest()
    {
        // Ensure the script continues to run even if the user aborts the connection.
        $originalLimit = ini_get('max_execution_time');
        $targetLimit = 600;
        $methods = [];
        if (function_exists('set_time_limit')) {
            @set_time_limit($targetLimit);
            $methods[] = 'set_time_limit';
        }
        if (function_exists('ini_set')) {
            @ini_set('max_execution_time', $targetLimit);
            $methods[] = 'ini_set';
        }
        $newLimit = ini_get('max_execution_time');
        $worked = $newLimit == $targetLimit || $newLimit == 0;
        Log::debug('Bootloader: Execution time limit', ['original' => $originalLimit, 'new' => $newLimit, 'worked' => $worked, 'methods' => !empty($methods) ? implode(', ', $methods) : 'none']);
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(\true);
        }
        $maxMemoryLimit = HttpUtils::maxMemoryLimit();
        if (function_exists('ini_set')) {
            @ini_set('memory_limit', $maxMemoryLimit);
            @ini_set('display_errors', \false);
        }
    }
    /**
     * Handle the request through Laravel HTTP kernel.
     *
     * @param IlluminateHttpKernel $kernel
     * @param Request $request
     * @return void
     */
    protected function handleRequest(IlluminateHttpKernel $kernel, Request $request): void
    {
        /**
         * @var IlluminateHttpKernel $kernel
         */
        $response = $kernel->handle($request);
        $body = $response->send();
        /**
         * Signal to PendingDispatch that we're about to terminate
         * because in some hosts the garbage collection can run
         * very slowly and cause the jobs to be dispatched
         * after the exit
         */
        Cache::driver('array')->forever('pending_dispatch_force_sync', \true);
        $kernel->terminate($request, $body);
        exit((int) $response->isServerError());
    }
    /**
     * @param IlluminateHttpKernel $kernel
     * @param Request $request
     * @return void
     * @link https://github.com/deliciousbrains/wp-background-processing/blob/master/classes/wp-async-request.php Method inspired by wp-background-processing
     */
    protected function bootCron(IlluminateHttpKernel $kernel, Request $request): void
    {
        // When is a cron request, WP takes care of forcing the shutdown to proxy server (nginx, apache)
        remove_action('shutdown', 'wp_ob_end_flush_all', 1);
        add_action('shutdown', fn() => $this->handleRequest($kernel, $request), 100);
    }
    /**
     * Run request validation pipeline for direct requests.
     *
     * This pipeline executes BEFORE WordPress initializes:
     * 1. AuthenticateRequest - JWT signature, claims (exp/iat), client_id, lbn
     * 2. FetchModularRequest - Fetch request from Modular API (type=request only)
     * 3. ValidateRoute - Route must exist (abort 404 if not)
     * 4. SetupAdminEnvironment - Admin constants, disable auto-actions, early login
     * 5. BeforeLogin - Pre-login hosting compatibility fixes
     *
     * Entry point validation (wp-load.php, User-Agent, params) is done in
     * bootHttp() via HttpUtils::isDirectRequest() before this pipeline runs.
     *
     * @param Request $request
     * @return Request
     */
    protected function runRequestValidationPipeline(Request $request): Request
    {
        return \Modular\ConnectorDependencies\app(Pipeline::class)->send($request)->through([AuthenticateRequest::class, FetchModularRequest::class, ValidateRoute::class, SetupAdminEnvironment::class, BeforeLogin::class])->then(fn(Request $request) => $request);
    }
    /**
     * Run request handler pipeline for direct requests.
     *
     * This pipeline executes on after_setup_theme (PHP_INT_MAX):
     * 1. ForceCompatibilities - YITH/Divi upgraders + YITH download fix + ThemeREX bridge
     * 2. then() - Register Laravel handler on wp_loaded hook
     *
     * @param IlluminateHttpKernel $kernel
     * @param Request $request
     * @return bool
     */
    protected function runRequestHandlerPipeline(IlluminateHttpKernel $kernel, Request $request): bool
    {
        return \Modular\ConnectorDependencies\app(Pipeline::class)->send($request)->through([ForceCompatibilities::class])->then(function (Request $request) use ($kernel) {
            // Register handler on wp_loaded hook
            add_action('wp_loaded', fn() => $this->handleRequest($kernel, $request));
            return \true;
        });
    }
    /**
     * Run cron handler pipeline for cron requests.
     *
     * Cron requests don't run the direct-request pipelines, so this pipeline
     * applies the compatibilities that must also cover cron-executed jobs
     * (e.g. MODULAR_CONNECTOR_LOOPBACK = false). Executes on
     * after_setup_theme (PHP_INT_MAX), before init:
     * 1. ForceCronCompatibilities - ThemeREX bridge
     * 2. then() - Register Laravel handler on shutdown hook (bootCron)
     *
     * @param IlluminateHttpKernel $kernel
     * @param Request $request
     * @return bool
     */
    protected function runCronHandlerPipeline(IlluminateHttpKernel $kernel, Request $request): bool
    {
        return \Modular\ConnectorDependencies\app(Pipeline::class)->send($request)->through([ForceCronCompatibilities::class])->then(function (Request $request) use ($kernel) {
            // Register handler on shutdown hook
            $this->bootCron($kernel, $request);
            return \true;
        });
    }
    /**
     * Check whether the request carries parameters that only belong to a direct request.
     *
     * This is what keeps the unauthenticated cron entry point away from Modular routes,
     * so it must cover every selector ModularRouteResolver reads (`type`, `x-mo-type`).
     * Extend it whenever a new selector is added there.
     *
     * @param Request $request
     * @return bool
     */
    protected function hasModularSelectors(Request $request): bool
    {
        foreach (['origin', 'type', 'sig', 'mrid'] as $parameter) {
            if ($request->has($parameter)) {
                return \true;
            }
        }
        foreach (['x-mo-type', 'x-mo-authentication'] as $header) {
            if ($request->hasHeader($header)) {
                return \true;
            }
        }
        return \false;
    }
    /**
     * Boot the Application for HTTP requests using 100% pipelines.
     *
     * Only two entry points are allowed:
     * - wp-cron.php with DOING_CRON (cron requests)
     * - wp-load.php with valid params (direct requests)
     *
     * Direct requests use two pipelines:
     * 1. Request Validation Pipeline (BEFORE WordPress init) - Security and validation
     * 2. Request Handler Pipeline (AFTER plugins_loaded) - Compatibilities and handler registration
     *
     * Cron requests use one pipeline:
     * 1. Cron Handler Pipeline (AFTER plugins_loaded) - Cron compatibilities,
     *    then register handler on shutdown hook
     *
     * wp-cron.php is unauthenticated and skips the validation pipeline, so cron requests
     * carrying direct-request parameters are rejected before anything boots.
     *
     * @param IlluminateHttpKernel $kernel
     * @param Request $request
     * @return void
     */
    protected function bootHttp(IlluminateHttpKernel $kernel, Request $request): void
    {
        $isCron = HttpUtils::isCron();
        $isDirectRequest = HttpUtils::isDirectRequest();
        // Only process cron (wp-cron.php) or direct request (wp-load.php)
        if (!$isCron && !$isDirectRequest) {
            return;
        }
        // wp-cron.php is reachable unauthenticated, so it must never carry Modular routing
        // or authentication selectors. A genuine cron request only ever gets doing_wp_cron.
        if ($isCron && $this->hasModularSelectors($request)) {
            Log::warning('Bootloader: Rejected cron request carrying Modular parameters', ['ip' => $request->ip(), 'uri' => $request->fullUrl()]);
            return;
        }
        Log::debug('Booting the Application for HTTP requests', ['is_direct_request' => HttpUtils::isDirectRequest(), 'is_cron' => HttpUtils::isCron(), 'uri' => $request->fullUrl()]);
        $this->configRequest();
        if ($isDirectRequest) {
            // Request Validation Pipeline: Execute BEFORE WordPress initialization
            $this->runRequestValidationPipeline($request);
        }
        // Both direct requests and cron execute in after_setup_theme hook
        add_action('after_setup_theme', function () use ($kernel, $request, $isDirectRequest, $isCron) {
            if ($isDirectRequest) {
                // Request Handler Pipeline: Compatibilities and handler registration
                $this->runRequestHandlerPipeline($kernel, $request);
            } elseif ($isCron) {
                // Cron Handler Pipeline: Cron compatibilities and shutdown handler
                $this->runCronHandlerPipeline($kernel, $request);
            }
        }, \PHP_INT_MAX);
    }
    public function boot(string $basePath, $callback)
    {
        if (!defined('LARAVEL_START')) {
            define('LARAVEL_START', microtime(\true));
        }
        $this->basePath = $basePath;
        $callback($this->getApplication());
        if ($this->app->hasBeenBootstrapped()) {
            return;
        }
        /**
         * @var IlluminateHttpKernel $kernel
         */
        $kernel = $this->app->make(IlluminateHttpKernel::class);
        $request = Request::capture();
        $this->app->instance('request', $request);
        Facade::clearResolvedInstance('request');
        $kernel->bootstrap();
        $this->bootHttp($kernel, $request);
    }
}
