<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation;

use Modular\ConnectorDependencies\Ares\Framework\Foundation\Compatibilities\LoginCompatibilities;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Database\Models\User;
use Modular\ConnectorDependencies\Illuminate\Support\Collection;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Cache;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
class ServerSetup
{
    /**
     * Refresh plugin updates check.
     *
     * Calls wp_update_plugins() twice: once with the 'load-update-core.php' hook,
     * and once without it, to ensure all update checks are triggered properly.
     *
     * @return void
     */
    public static function refreshPluginUpdates(): void
    {
        global $wp_current_filter;
        $wp_current_filter[] = 'load-update-core.php';
        wp_update_plugins();
        array_pop($wp_current_filter);
        wp_update_plugins();
    }
    /**
     * Refresh theme updates check.
     *
     * Calls wp_update_themes() twice: once with the 'load-update-core.php' hook,
     * and once without it, to ensure all update checks are triggered properly.
     *
     * @return void
     */
    public static function refreshThemeUpdates(): void
    {
        global $wp_current_filter;
        $wp_current_filter[] = 'load-update-core.php';
        wp_update_themes();
        array_pop($wp_current_filter);
        wp_update_themes();
    }
    /**
     * Refresh WordPress core updates check.
     *
     * Calls wp_version_check() twice:
     * 1. First call: Uses cached transient if available
     * 2. Second call with $force_check=true: Bypasses cache and forces fresh check
     *
     * This dual-call approach ensures:
     * - Fast response if updates were recently checked (uses cache)
     * - Guaranteed fresh data from wordpress.org API (forced check)
     *
     * @return void
     */
    public static function refreshCoreUpdates(): void
    {
        if (!function_exists('wp_version_check')) {
            return;
        }
        wp_version_check();
        // Check with cache
        wp_version_check([], \true);
        // Force check (bypass transient)
    }
    /**
     * @param null $user
     * @param bool $withCookies
     * @return array
     */
    public static function loginAs($user = null, bool $withCookies = \false)
    {
        if (!function_exists('wp_set_current_user')) {
            include_once \ABSPATH . '/wp-includes/pluggable.php';
        }
        wp_cookie_constants();
        if (!$withCookies && is_user_logged_in()) {
            return [];
        }
        $user = $user ?: self::getAdminUser();
        if (!$user) {
            return [];
        }
        $id = intval(\Modular\ConnectorDependencies\data_get($user, 'ID'));
        Log::debug('Login as user', ['user' => $id, 'login' => $user->user_login]);
        wp_set_current_user($id, \Modular\ConnectorDependencies\data_get($user, 'user_login'));
        if ($withCookies) {
            LoginCompatibilities::afterLogin($id);
            try {
                wp_set_auth_cookie($id);
                return LoginCompatibilities::hostingCookies(is_ssl());
            } catch (\Throwable $e) {
                // Silence is golden
                Log::error($e, ['context' => 'Error setting auth cookie during login as user']);
            }
        }
    }
    /**
     * @return array|false|mixed|\WP_User
     */
    public static function getAdminUser()
    {
        static::ensureUserFunctionsLoaded();
        $userId = Cache::driver('wordpress')->get('user.login');
        if ($userId) {
            $user = get_user_by('id', $userId);
            if ($user) {
                return $user;
            }
        }
        return static::getAllAdminUsers(1)->first();
    }
    /**
     * Set cookies in $_COOKIE superglobal for internal verification.
     *
     * IMPORTANT: This does NOT use setcookie() because:
     * 1. We're in background jobs/queue (no HTTP response to send cookies to)
     * 2. WordPress may have already sent headers (setcookie() would fail)
     * 3. Hosting providers check $_COOKIE directly, not HTTP headers
     *
     * @param array<\Symfony\Component\HttpFoundation\Cookie> $cookies
     * @return void
     */
    public static function setCookies(array $cookies): void
    {
        if (empty($cookies)) {
            return;
        }
        foreach ($cookies as $cookie) {
            $_COOKIE[$cookie->getName()] = $cookie->getValue();
            Log::debug('ServerSetup: Set cookie in $_COOKIE superglobal', ['name' => $cookie->getName(), 'value_length' => strlen($cookie->getValue()), 'secure' => $cookie->isSecure()]);
        }
    }
    /**
     * @return void
     */
    public static function logout()
    {
        if (!function_exists('wp_logout')) {
            include_once \ABSPATH . '/wp-includes/pluggable.php';
        }
        try {
            // Emulate the logout process without do_action( 'wp_logout', $user_id );
            wp_set_current_user(0);
        } catch (\Throwable $e) {
            // Silence is golden
        }
    }
    /**
     * @return array|false|mixed
     */
    public static function getAllAdminUsers(?int $limit = null)
    {
        static::ensureUserFunctionsLoaded();
        if (is_multisite()) {
            $query = User::whereIn('user_login', get_super_admins());
            if ($limit) {
                $query->limit($limit);
            }
            return $query->get();
        }
        $query = User::whereHas('meta', function ($q) {
            $q->where('meta_key', 'LIKE', '%capabilities%');
            $q->where('meta_value', 'LIKE', '%administrator%');
        });
        if ($limit) {
            $query->limit($limit);
        }
        $users = $query->get();
        if ($users->isNotEmpty()) {
            return $users;
        }
        $args = ['role' => 'administrator'];
        if ($limit) {
            $args['number'] = $limit;
        }
        return Collection::make(get_users($args))->map(fn(\WP_User $user) => (new User())->forceFill(get_object_vars($user->data)));
    }
    /**
     * Determine if async signals are supported.
     *
     * Checks for PCNTL extension functions required for signal handling
     * and verifies they are not disabled in php.ini.
     *
     * @return bool
     */
    public static function supportsAsyncSignals(): bool
    {
        $functions = ['pcntl_signal', 'pcntl_alarm', 'pcntl_async_signals', 'posix_kill'];
        foreach ($functions as $function) {
            if (!function_exists($function)) {
                return \false;
            }
        }
        $disabledFunctions = explode(',', @ini_get('disable_functions'));
        foreach ($functions as $function) {
            if (in_array($function, $disabledFunctions)) {
                return \false;
            }
        }
        return \true;
    }
    /**
     * Ensure WordPress user functions are loaded.
     *
     * @return void
     */
    private static function ensureUserFunctionsLoaded(): void
    {
        if (!function_exists('get_user_by')) {
            require_once \ABSPATH . \WPINC . '/pluggable.php';
        }
        if (!function_exists('get_super_admins')) {
            require_once \ABSPATH . \WPINC . '/capabilities.php';
        }
    }
}
