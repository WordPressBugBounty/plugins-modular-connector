<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation;

use Modular\ConnectorDependencies\Ares\Framework\Foundation\Compatibilities\Compatibilities;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Http\HttpUtils;
use Modular\ConnectorDependencies\Symfony\Component\HttpFoundation\Cookie;
class ScreenSimulation
{
    /**
     * Indicates if the screen has "booted".
     *
     * @var bool
     */
    protected $booted = \false;
    /**
     * Indicates if the screen has "loaded".
     *
     * @var bool
     */
    protected $loaded = \false;
    /**
     * The Bootloader instance.
     */
    protected static $instance;
    /**
     * @var mixed
     */
    protected $callResponse;
    /**
     * @var mixed
     */
    protected $callBody;
    /**
     * Get the Bootloader instance.
     */
    public static function getInstance(): self
    {
        return static::$instance ??= new static();
    }
    /**
     * Determine if the screen has booted.
     *
     * @return bool
     */
    public function isBooted()
    {
        return $this->booted;
    }
    /**
     * Determine if the screen has booted.
     *
     * @return bool
     */
    public function isLoaded()
    {
        return $this->loaded;
    }
    /**
     * Boot the screen's service providers.
     *
     * @return void
     */
    public function boot(string $path = 'update-core.php')
    {
        if ($this->isBooted()) {
            return;
        }
        // A lot of sites have issues with the updater, so we need to tell WordPress that we are in the update-core.php file.
        $_SERVER['PHP_SELF'] = '/wp-admin/' . $path;
        $_COOKIE['redirect_count'] = '10';
        if (defined('FORCE_SSL_ADMIN') && \FORCE_SSL_ADMIN) {
            $_SERVER['HTTPS'] = 'on';
            $_SERVER['SERVER_PORT'] = '443';
        }
        if (!isset($GLOBALS['pagenow'])) {
            $GLOBALS['pagenow'] = $path;
        }
        if (!class_exists('WP_Screen')) {
            require_once \ABSPATH . 'wp-admin/includes/class-wp-screen.php';
        }
        if (!function_exists('set_current_screen')) {
            require_once \ABSPATH . 'wp-admin/includes/screen.php';
        }
        if (!function_exists('add_meta_box')) {
            require_once \ABSPATH . 'wp-admin/includes/template.php';
        }
        $this->loadAdmin();
        $this->loadLogin();
        $this->forceCompability();
        $this->includeUpgrader();
        // Force login as admin.
        ServerSetup::loginAs();
        \Modular\ConnectorDependencies\app()->terminating(function () {
            ServerSetup::logout();
        });
        $this->booted = \true;
    }
    /**
     * Makes the necessary WordPress upgrader includes
     * to handle plugin and themes functionality.
     *
     * @return void
     */
    public function includeUpgrader(): void
    {
        if (!function_exists('wp_update_plugins') || !function_exists('wp_update_themes')) {
            ob_start();
            require_once \ABSPATH . 'wp-admin/includes/update.php';
            ob_end_flush();
            ob_end_clean();
        }
        if (!class_exists('WP_Upgrader')) {
            require_once \ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        }
        if (!function_exists('wp_install')) {
            require_once \ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        if (!function_exists('plugins_api')) {
            require_once \ABSPATH . 'wp-admin/includes/plugin-install.php';
        }
        if (empty($GLOBALS['wp_filesystem'])) {
            WP_Filesystem();
        }
        if (empty($GLOBALS['wp_theme_directories'])) {
            register_theme_directory(get_theme_root());
        }
    }
    /**
     * @param $response
     * @param $args
     * @param $url
     * @return mixed
     */
    public function interceptUpdateCall($response, $args, $url)
    {
        if ($url !== 'https://api.wordpress.org/plugins/update-check/1.1/') {
            return $response;
        }
        $this->callResponse = $response;
        $this->callBody = $args['body'];
        return $response;
    }
    /**
     * @param $response
     * @param $args
     * @param $url
     * @return mixed
     */
    public function interceptUpdateCache($response, $args, $url)
    {
        if ($url !== 'https://api.wordpress.org/plugins/update-check/1.1/') {
            return $response;
        }
        if ($this->callResponse === null) {
            return $response;
        }
        if ($this->callBody !== http_build_query($args['body'])) {
            return $response;
        }
        return $this->callResponse;
    }
    /**
     * @return void
     */
    private function loadAdmin()
    {
        if (!defined('WP_ADMIN')) {
            define('WP_ADMIN', \true);
        }
        if (!defined('WP_NETWORK_ADMIN')) {
            define('WP_NETWORK_ADMIN', HttpUtils::isMultisite());
        }
        if (!defined('WP_USER_ADMIN')) {
            define('WP_USER_ADMIN', \false);
        }
        if (!defined('WP_BLOG_ADMIN')) {
            define('WP_BLOG_ADMIN', \true);
        }
        if (!isset($GLOBALS['hook_suffix'])) {
            $GLOBALS['hook_suffix'] = '';
        }
        add_action('wp_loaded', function () {
            // When we're in Direct Request (wp-load.php) or wp-cron.php, we need to load the main admin files.
            if (HttpUtils::isDirectRequest() || HttpUtils::isCron()) {
                // Initialize ob_start to avoid any content that has already been sent.
                @ob_start();
                require_once \ABSPATH . 'wp-admin/includes/admin.php';
                do_action('admin_init');
                @ob_end_flush();
                @ob_end_clean();
            }
            set_current_screen();
            do_action('load-update-core.php');
        }, \PHP_INT_MAX);
    }
    /**
     * @return void
     */
    private function loadLogin()
    {
        add_filter('ares/login/match', function ($cookies, $userId, $isSecure) {
            if (defined('WPE_APIKEY')) {
                $value = hash('sha256', 'wpe_auth_salty_dog|' . \WPE_APIKEY);
                $expires = 0;
                // 0 means the cookie will expire when the browser is closed.
                $cookies[] = new Cookie('wpe-auth', $value, $expires, '/', '', $isSecure, \true);
            }
            return $cookies;
        }, 10, 3);
    }
    /**
     * @return void
     */
    private function forceCompability()
    {
        add_filter('http_response', [$this, 'interceptUpdateCall'], \PHP_INT_MAX, 3);
        add_filter('pre_http_request', [$this, 'interceptUpdateCache'], \PHP_INT_MAX, 3);
        $compatibilityFixes = Compatibilities::getCompatibilityFixes();
        array_walk($compatibilityFixes, fn($compatibility) => $compatibility::fix());
    }
}
