<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Http\Pipeline;

use Modular\ConnectorDependencies\Ares\Framework\Foundation\Compatibilities\LoginCompatibilities;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\ServerSetup;
use Closure;
use Modular\ConnectorDependencies\Illuminate\Http\Request;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
/**
 * Force compatibility fixes for hosting providers and YITH plugins.
 *
 * This pipe handles two types of compatibility fixes:
 * 1. YITH Upgrader - Forces registration of YITH upgrade system that initializes late
 * 2. Hosting Cookies - Sets hosting-specific cookies (WP Engine, etc.)
 *
 * Note: User login is handled earlier by SetupAdminEnvironment so themes
 * with authorization gates register their hooks during WordPress init.
 *
 * Runs AFTER BeforeLogin compatibility fixes are applied.
 */
class ForceCompatibilities
{
    /**
     * Handle the compatibility fixes.
     *
     * @param Request $request
     * @param Closure $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // 1. Register YITH upgrade system that initializes late on wp_loaded priority 99
        try {
            $this->registerYithUpgrader();
        } catch (\Throwable $e) {
            Log::warning('ForceCompatibilities: YITH upgrader failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        }
        // 2. Set hosting-specific cookies
        try {
            $this->setHostingCookies();
        } catch (\Throwable $e) {
            Log::warning('ForceCompatibilities: Hosting cookies failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        }
        return $next($request);
    }
    /**
     * Set hosting-specific cookies.
     *
     * Generates and sets cookies required by hosting providers
     * (e.g., WP Engine's wpe-auth for filesystem access).
     *
     * @return void
     */
    private function setHostingCookies(): void
    {
        $cookies = LoginCompatibilities::hostingCookies(is_ssl());
        // Set cookies in $_COOKIE superglobal
        ServerSetup::setCookies($cookies);
    }
    /**
     * Register YITH upgrade system if it hasn't been registered yet.
     *
     * YITH plugins register their license and update systems on wp_loaded with priority 99,
     * but we execute at priority 10, so we need to call their callbacks manually.
     *
     * This method is YITH-specific. If other premium plugins need similar treatment,
     * add separate methods for each (e.g., registerAnotherPluginUpgrader()).
     *
     * @return void
     */
    private function registerYithUpgrader(): void
    {
        global $wp_filter;
        // Check if wp_loaded hook has actions registered at priority 99
        if (!isset($wp_filter['wp_loaded']) || !isset($wp_filter['wp_loaded']->callbacks[99])) {
            return;
        }
        // Get all callbacks registered at priority 99 on wp_loaded
        $callbacks = $wp_filter['wp_loaded']->callbacks[99];
        foreach ($callbacks as $callback) {
            // Check if this is a YITH callback
            if (!$this->isYithCallback($callback)) {
                continue;
            }
            // Execute the YITH callback manually
            if (is_callable($callback['function'])) {
                call_user_func($callback['function']);
            }
        }
    }
    /**
     * Check if a callback belongs to a YITH plugin.
     *
     * @param array $callback The callback data from wp_filter
     * @return bool
     */
    private function isYithCallback(array $callback): bool
    {
        $function = $callback['function'];
        // Array callback: [object, method]
        if (is_array($function) && isset($function[0])) {
            $object = $function[0];
            // Check if object's class name contains YITH
            if (is_object($object)) {
                $className = get_class($object);
                return stripos($className, 'YITH') !== \false || stripos($className, 'YIT') !== \false;
            }
        }
        // String callback: 'function_name' or 'Class::method'
        if (is_string($function)) {
            return stripos($function, 'yith') !== \false || stripos($function, 'yit') !== \false;
        }
        return \false;
    }
    /**
     * Get a readable name for a callback (for logging).
     *
     * @param mixed $callback
     * @return string
     */
    private function getCallbackName($callback): string
    {
        if (is_array($callback) && isset($callback[0], $callback[1])) {
            $class = is_object($callback[0]) ? get_class($callback[0]) : $callback[0];
            return $class . '::' . $callback[1];
        }
        if (is_string($callback)) {
            return $callback;
        }
        return 'unknown';
    }
}
