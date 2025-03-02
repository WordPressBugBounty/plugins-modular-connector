<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation;

use Modular\ConnectorDependencies\Ares\Framework\Foundation\Auth\JWT;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Http\HttpUtils;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Routing\Router;
use Modular\ConnectorDependencies\Illuminate\Contracts\Foundation\Application as ApplicationContract;
use Modular\ConnectorDependencies\Illuminate\Contracts\Http\Kernel as IlluminateHttpKernel;
use Modular\ConnectorDependencies\Illuminate\Http\Request;
use Modular\ConnectorDependencies\Illuminate\Http\Response;
use Modular\ConnectorDependencies\Illuminate\Support\Collection;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Facade;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use Modular\ConnectorDependencies\Illuminate\Support\Str;
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
     * @param Request $request
     * @return bool
     */
    protected function getPath(Request $request)
    {
        return rtrim($request->getBaseUrl() . $request->getPathInfo(), '/');
    }
    /**
     * @param string $path
     * @return bool
     */
    protected function isWpLoad(string $path)
    {
        return Str::endsWith($path, 'wp-load.php');
    }
    /**
     * @param string $path
     * @return bool
     */
    protected function isAjax(string $path)
    {
        return Str::endsWith($path, 'admin-ajax.php');
    }
    /**
     * @param Request $request
     * @return bool
     */
    protected function isExcluded(Request $request)
    {
        $except = Collection::make([admin_url(), wp_login_url(), wp_registration_url()])->map(fn($url) => parse_url($url, \PHP_URL_PATH))->unique()->filter();
        $path = $this->getPath($request);
        return !HttpUtils::isAjax() && !$this->isWpLoad($path) && (Str::startsWith($path, $except->all()) || Str::endsWith($path, '.php'));
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
        if (function_exists('set_time_limit')) {
            @set_time_limit(600);
        }
        $maxMemoryLimit = HttpUtils::maxMemoryLimit();
        if (function_exists('ini_set')) {
            @ini_set('memory_limit', $maxMemoryLimit);
            @ini_set('display_errors', \false);
        }
        if (HttpUtils::isDirectRequest() || HttpUtils::isAjax()) {
            // Ensure the script continues to run even if the user aborts the connection.
            HttpUtils::configMaxLimit(\false);
            if (HttpUtils::isDirectRequest() && !defined('DOING_AJAX')) {
                define('DOING_AJAX', \true);
            }
            // When it's a modular request, we need to avoid the cron execution.
            remove_action('init', 'wp_cron');
            // We use Laravel Response to make our redirections.
            add_filter('wp_redirect', '__return_false');
        }
        // We're just before the WordPress bootstrap, so we can load the admin files.
        ScreenSimulation::getInstance()->boot();
    }
    /**
     * Register the default WordPress route to avoid Not Found errors.
     *
     * @return void
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    protected function registerDefaultRoute(): void
    {
        $this->app->make('router')->any('{any?}', fn() => \Modular\ConnectorDependencies\tap(\Modular\ConnectorDependencies\response(''), function (Response $response) {
            foreach (headers_list() as $header) {
                [$header, $value] = explode(': ', $header, 2);
                if (!headers_sent()) {
                    header_remove($header);
                }
                $response->header($header, $value, $header !== 'Set-Cookie');
            }
            if ($this->app->hasDebugModeEnabled()) {
                $response->header('X-Powered-By', $this->app->version());
            }
            $content = '';
            $levels = ob_get_level();
            for ($i = 0; $i < $levels; $i++) {
                $content .= ob_get_clean();
            }
            $response->setContent($content);
        }))->where('any', '.*')->name('wordpress');
    }
    /**
     * Handle the request.
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
     * Register the request handler.
     *
     * @param IlluminateHttpKernel $kernel
     * @param Request $request
     * @return void
     */
    protected function registerRequestHandler(IlluminateHttpKernel $kernel, Request $request): void
    {
        $path = $this->getPath($request);
        $isWpLoad = $this->isWpLoad($path);
        // Handle the request if the route exists
        $hook = $isWpLoad ? 'wp_loaded' : 'parse_request';
        add_action($hook, fn() => $this->handleRequest($kernel, $request));
    }
    /**
     * @param IlluminateHttpKernel $kernel
     * @param Request $request
     * @return void
     * @link https://github.com/deliciousbrains/wp-background-processing/blob/master/classes/wp-async-request.php Method inspired by wp-background-processing
     */
    protected function bootAjax(IlluminateHttpKernel $kernel, Request $request): void
    {
        if (!HttpUtils::isAjax() && !HttpUtils::isCron()) {
            return;
        }
        HttpUtils::configMaxLimit();
        // If the request is an AJAX request, we need to check the nonce.
        if (HttpUtils::isAjax()) {
            // Don't lock up other requests while processing.
            session_write_close();
            $action = $kernel->getApplication()->getScheduleHook();
            if ($request->hasHeader('Authentication')) {
                $authHeader = $request->header('Authentication', '');
                if (!JWT::verify($authHeader, $action)) {
                    wp_die(sprintf('Invalid token for %s', $action), 403);
                }
            } else {
                $isValid = check_ajax_referer($action, 'nonce', \false);
                if (!$isValid) {
                    wp_die(sprintf('Invalid nonce for %s', $action), 403);
                }
            }
            // If this is an AJAX request, we need to force close the connection to avoid the server hanging.
            add_action($action, fn() => HttpUtils::forceCloseConnection(), 1);
        }
        // When is a cron request, WP takes care of forcing the shutdown to proxy server (nginx, apache)
        remove_action('shutdown', 'wp_ob_end_flush_all', 1);
        add_action('shutdown', fn() => $this->handleRequest($kernel, $request), 100);
    }
    /**
     * Boot the Application for HTTP requests.
     *
     * @param IlluminateHttpKernel $kernel
     * @param Request $request
     * @return void
     */
    protected function bootHttp(IlluminateHttpKernel $kernel, Request $request): void
    {
        if (!HttpUtils::isCron() && ($this->isExcluded($request) || !HttpUtils::isDirectRequest() && !HttpUtils::isAjax())) {
            return;
        }
        Log::debug('Booting the Application for HTTP requests', ['is_direct_request' => HttpUtils::isDirectRequest(), 'is_ajax' => HttpUtils::isAjax(), 'is_cron' => HttpUtils::isCron()]);
        $this->configRequest();
        $this->registerDefaultRoute();
        try {
            // First, we need to search for the route to confirm if it exists and check if the modular request is valid.
            $routes = $this->app->make('router')->getRoutes();
            $route = apply_filters('ares/routes/match', $routes->match($request), \false);
            if (HttpUtils::isDirectRequest()) {
                if ($route->getName() === 'wordpress') {
                    // If the route is not found, return false.
                    \Modular\ConnectorDependencies\abort(404);
                }
                add_action('after_setup_theme', fn() => $this->registerRequestHandler($kernel, $request), 0);
            } elseif (HttpUtils::isAjax() || HttpUtils::isCron()) {
                $this->bootAjax($kernel, $request);
            }
        } catch (\Throwable $e) {
            throw $e;
        }
    }
    public function boot(string $basePath, $callback)
    {
        add_action('plugins_loaded', function () use ($basePath, $callback) {
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
        }, 0);
    }
}
