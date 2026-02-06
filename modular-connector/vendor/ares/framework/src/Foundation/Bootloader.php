<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation;

use Modular\ConnectorDependencies\Ares\Framework\Foundation\Http\HttpUtils;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Http\Pipeline\AuthenticateRequest;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Http\Pipeline\BeforeLogin;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Http\Pipeline\FetchModularRequest;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Http\Pipeline\ForceCompatibilities;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Http\Pipeline\SetupAdminEnvironment;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Http\Pipeline\ValidateRoute;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Routing\Router;
use Modular\ConnectorDependencies\Illuminate\Contracts\Foundation\Application as ApplicationContract;
use Modular\ConnectorDependencies\Illuminate\Contracts\Http\Kernel as IlluminateHttpKernel;
use Modular\ConnectorDependencies\Illuminate\Http\Request;
use Modular\ConnectorDependencies\Illuminate\Http\Response;
use Modular\ConnectorDependencies\Illuminate\Pipeline\Pipeline;
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
        if (function_exists('set_time_limit')) {
            @set_time_limit(600);
        }
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
     * This pipeline executes BEFORE WordPress initializes and validates:
     * 1. AuthenticateRequest - JWT signature, claims (exp/iat), client_id, lbn
     * 2. SetupAdminEnvironment - Admin constants, disable auto-actions
     *
     * Note: Entry point validation (wp-load.php, User-Agent, params) is done in
     * bootHttp() via HttpUtils::isDirectRequest() before running this pipeline.
     *
     * IMPORTANT: Uses ->then() instead of ->thenReturn() to ensure all pipes
     * execute correctly. If any pipe fails to return $next($request), the
     * callback won't execute and the pipeline will fail silently.
     *
     * @param Request $request
     * @return void
     */
    protected function runRequestValidationPipeline(Request $request): Request
    {
        return \Modular\ConnectorDependencies\app(Pipeline::class)->send($request)->through([AuthenticateRequest::class, FetchModularRequest::class, ValidateRoute::class, SetupAdminEnvironment::class])->then(fn(Request $request) => $request);
    }
    /**
     * Run request handler pipeline for direct requests.
     *
     * This pipeline executes AFTER WordPress plugins_loaded and handles:
     * 1. FetchModularRequest - Fetch request data from Modular API (type=request only)
     * 2. ValidateRoute - Route validation (abort if route is 'default')
     * 3. BeforeLogin - Pre-login compatibility fixes
     * 4. ForceCompatibilities - Force premium plugins + user login
     * 5. Then execute request handler via wp_loaded hook
     *
     * IMPORTANT: ValidateRoute runs here (not in validation pipeline) because:
     * - Route resolution requires WordPress to be fully loaded
     * - At this point we already validated this is a Modular request
     * - A route MUST exist - if not, it's a 404 from our application
     *
     * @param IlluminateHttpKernel $kernel
     * @param Request $request
     * @return void
     */
    protected function runRequestHandlerPipeline(IlluminateHttpKernel $kernel, Request $request): bool
    {
        return \Modular\ConnectorDependencies\app(Pipeline::class)->send($request)->through([BeforeLogin::class, ForceCompatibilities::class])->then(function (Request $request) use ($kernel) {
            // Register handler on wp_loaded hook
            add_action('wp_loaded', fn() => $this->handleRequest($kernel, $request));
            return \true;
        });
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
     * 2. Request Handler Pipeline (AFTER plugins_loaded) - Login and handler registration
     *
     * Cron requests:
     * - Execute AFTER plugins_loaded (WordPress must be fully initialized)
     * - Register handler on shutdown hook
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
        Log::debug('Booting the Application for HTTP requests', ['is_direct_request' => HttpUtils::isDirectRequest(), 'is_cron' => HttpUtils::isCron(), 'uri' => $request->fullUrl()]);
        $this->configRequest();
        if ($isDirectRequest) {
            // Request Validation Pipeline: Execute BEFORE WordPress initialization
            $this->runRequestValidationPipeline($request);
        }
        // Both direct requests and cron execute in plugins_loaded hook
        add_action('after_setup_theme', function () use ($kernel, $request, $isDirectRequest, $isCron) {
            if ($isDirectRequest) {
                // Request Handler Pipeline: Login and handler registration
                $this->runRequestHandlerPipeline($kernel, $request);
            } elseif ($isCron) {
                // Cron: Register handler on shutdown hook
                $this->bootCron($kernel, $request);
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
