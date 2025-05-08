<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation;

use Modular\ConnectorDependencies\Ares\Framework\Foundation\Database\Models\User;
use Modular\ConnectorDependencies\Illuminate\Support\Collection;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Cache;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
class ServerSetup
{
    /**
     * @return void
     */
    public static function clean()
    {
        global $wp_current_filter;
        $wp_current_filter[] = 'load-update-core.php';
        // Force clean cache.
        if (function_exists('wp_clean_update_cache')) {
            wp_clean_update_cache();
        }
        wp_update_plugins();
        wp_update_themes();
        array_pop($wp_current_filter);
        /**
         * This hook call to wp_update_plugins() and wp_update_themes() is necessary to avoid issues with the updater.
         *
         * @see wp_update_plugins
         * @see wp_update_themes
         */
        set_current_screen();
        do_action('load-update-core.php');
        wp_version_check();
        wp_version_check([], \true);
    }
    /**
     * @param null $user
     * @param bool $withCookies
     * @return void
     */
    public static function loginAs($user = null, bool $withCookies = \false)
    {
        if (!function_exists('wp_set_current_user')) {
            include_once \ABSPATH . '/wp-includes/pluggable.php';
        }
        if (!$withCookies && is_user_logged_in()) {
            return;
        }
        if (!$user) {
            $user = self::getAdminUser();
        }
        if (!$user) {
            return;
        }
        Log::debug('Login as user', ['user' => $user->ID, 'login' => $user->user_login]);
        // Authenticated user
        wp_cookie_constants();
        $id = intval(\Modular\ConnectorDependencies\data_get($user, 'ID'));
        // Log in with the new user
        wp_set_current_user($id, \Modular\ConnectorDependencies\data_get($user, 'user_login'));
        if ($withCookies) {
            try {
                wp_set_auth_cookie($id);
            } catch (\Throwable $e) {
                // Silence is golden
            }
        }
        return apply_filters('ares/login/match', [], $id, is_ssl());
    }
    /**
     * @return array|false|mixed|\WP_User
     */
    public static function getAdminUser()
    {
        if (!function_exists('get_user_by')) {
            require_once \ABSPATH . \WPINC . '/pluggable.php';
        }
        if (!function_exists('get_super_admins')) {
            require_once \ABSPATH . \WPINC . '/capabilities.php';
        }
        $userId = Cache::driver('wordpress')->get('user.login');
        if ($userId) {
            $user = get_user_by('id', $userId);
            if ($user) {
                return $user;
            }
        }
        if (is_multisite()) {
            $users = get_super_admins();
            if (!empty($users)) {
                $user = \Modular\ConnectorDependencies\data_get($users, 0);
                return get_user_by('login', $user);
            }
        }
        global $wpdb;
        $users = $wpdb->get_results("SELECT *\n\t\t\tFROM {$wpdb->users} u\n\t\t\tINNER JOIN {$wpdb->usermeta} um ON u.ID = um.user_id\n\t\t\tWHERE um.meta_key = '{$wpdb->prefix}capabilities'\n\t\t\tAND um.meta_value LIKE '%administrator%'\n\t\t\tLIMIT 1\n\t\t\t");
        $user = \Modular\ConnectorDependencies\data_get($users, 0);
        if ($user) {
            return $user;
        }
        $users = get_users(['role' => 'administrator']);
        return \Modular\ConnectorDependencies\data_get($users, 0);
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
    public static function getAllAdminUsers()
    {
        if (!function_exists('get_user_by')) {
            require_once \ABSPATH . \WPINC . '/pluggable.php';
        }
        if (!function_exists('get_super_admins')) {
            require_once \ABSPATH . \WPINC . '/capabilities.php';
        }
        if (is_multisite()) {
            return User::whereIn('user_login', get_super_admins())->get();
        }
        $users = User::whereHas('meta', function ($q) {
            $q->where('meta_key', 'LIKE', '%capabilities%');
            $q->where('meta_value', 'LIKE', '%administrator%');
        })->get();
        if (!empty($users)) {
            return $users;
        }
        return Collection::make(get_users(['role' => 'administrator']))->map(fn(\WP_User $user) => (new User())->forceFill(get_object_vars($user->data)));
    }
}
