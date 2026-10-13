<?php

namespace Modular\Connector\Helper;

use Modular\ConnectorDependencies\Illuminate\Support\Str;

/**
 * Detects the managed hosting provider from the constants, classes and server variables
 * each platform injects. Plugins anyone can install (cache or optimizer plugins) are never
 * a signal: they don't prove where the site is hosted.
 *
 * Only local signals: never an HTTP or DNS call. Config is read while mu-plugins load,
 * before the host's own mu-plugin classes may exist, so classes are a secondary signal.
 */
class Hosting
{
    public const WPENGINE = 'wpengine';
    public const FLYWHEEL = 'flywheel';
    public const PRESSABLE = 'pressable';
    public const WORDPRESSCOM = 'wordpresscom';
    public const WPVIP = 'wpvip';
    public const GODADDY = 'godaddy';
    public const PAGELY = 'pagely';
    public const ONECOM = 'onecom';
    public const PROISP = 'proisp';
    public const IONOS = 'ionos';
    public const KINSTA = 'kinsta';
    public const CLOUDWAYS = 'cloudways';
    public const SPINUPWP = 'spinupwp';
    public const PANTHEON = 'pantheon';
    public const DREAMPRESS = 'dreampress';
    public const GRIDPANE = 'gridpane';
    public const WPMUDEV = 'wpmudev';
    public const ROCKETNET = 'rocketnet';
    public const WPX = 'wpx';
    public const SITEGROUND = 'siteground';
    public const HOSTINGER = 'hostinger';
    public const SERVEBOLT = 'servebolt';
    public const SERAVO = 'seravo';
    public const PRESSIDIUM = 'pressidium';
    public const SAVVII = 'savvii';
    public const WPSERVEUR = 'wpserveur';
    public const O2SWITCH = 'o2switch';

    /**
     * Hosts where our loopback must stay off: the request it fires cannot write to disk.
     * Jobs run through native wp-cron instead, as with MODULAR_CONNECTOR_LOOPBACK = false.
     */
    private const WITHOUT_LOOPBACK = [
        self::WPENGINE,
    ];

    /**
     * One detector per company, in order: the first non-null slug wins.
     */
    private const DETECTORS = [
        'wpEngine',
        'automattic',
        'goDaddy',
        'groupOne',
        'ionos',
        'kinsta',
        'cloudways',
        'spinupWp',
        'pantheon',
        'dreamHost',
        'gridPane',
        'wpmuDev',
        'rocketNet',
        'wpx',
        'siteGround',
        'hostinger',
        'servebolt',
        'seravo',
        'pressidium',
        'savvii',
        'wpServeur',
        'o2switch',
    ];

    /**
     * @return string|null
     */
    public static function detect(): ?string
    {
        foreach (self::DETECTORS as $detector) {
            $host = static::$detector();

            if ($host !== null) {
                return $host;
            }
        }

        return null;
    }

    /**
     * @return bool
     */
    public static function supportsLoopback(): bool
    {
        return !in_array(static::detect(), self::WITHOUT_LOOPBACK, true);
    }

    /**
     * WP Engine and Flywheel, which WP Engine owns.
     *
     * @return string|null
     */
    private static function wpEngine(): ?string
    {
        if (isset($_SERVER['IS_WPE']) || getenv('IS_WPE') || getenv('WPENGINE_ACCOUNT') || defined('WPE_APIKEY') || class_exists('WpeCommon', false)) {
            return self::WPENGINE;
        }

        if (Str::startsWith($_SERVER['SERVER_SOFTWARE'] ?? '', 'Flywheel/') || defined('FLYWHEEL_CONFIG_DIR') || class_exists('FlywheelNginxCompat', false)) {
            return self::FLYWHEEL;
        }

        return null;
    }

    /**
     * Pressable, WordPress.com and WordPress VIP.
     *
     * @return string|null
     */
    private static function automattic(): ?string
    {
        // Before WordPress.com: IS_ATOMIC is defined on both platforms, IS_PRESSABLE only here.
        if (defined('IS_PRESSABLE') && IS_PRESSABLE) {
            return self::PRESSABLE;
        }

        if (defined('WPCOMSH_VERSION') || (defined('ATOMIC_SITE_ID') && defined('ATOMIC_CLIENT_ID'))) {
            return self::WORDPRESSCOM;
        }

        // VIP's own mu-plugins define it as false outside VIP.
        if (defined('WPCOM_IS_VIP_ENV') && WPCOM_IS_VIP_ENV === true) {
            return self::WPVIP;
        }

        return null;
    }

    /**
     * GoDaddy Managed WordPress and Pagely, which GoDaddy owns.
     *
     * @return string|null
     */
    private static function goDaddy(): ?string
    {
        if (defined('GD_SYSTEM_PLUGIN_DIR') || class_exists('\WPaaS\Plugin', false)) {
            return self::GODADDY;
        }

        if (defined('PAGELYBIN')) {
            return self::PAGELY;
        }

        return null;
    }

    /**
     * one.com and PRO ISP.
     *
     * @return string|null
     */
    private static function groupOne(): ?string
    {
        $brands = [
            'one.com' => self::ONECOM,
            'proisp.no' => self::PROISP,
        ];

        return $brands[strtolower($_SERVER['GROUPONE_BRAND_NAME'] ?? '')] ?? null;
    }

    /**
     * Managed WordPress ("Stretch", flagged by IONOS's own mu-plugin) and classic shared hosting.
     *
     * @return string|null
     */
    private static function ionos(): ?string
    {
        if ((defined('IONOS_IS_STRETCH') && IONOS_IS_STRETCH) || Str::startsWith(static::abspath(), ['/home/www/public', '/kunden/homepages/'])) {
            return self::IONOS;
        }

        return null;
    }

    /**
     * @return string|null
     */
    private static function kinsta(): ?string
    {
        return isset($_SERVER['KINSTA_CACHE_ZONE']) || defined('KINSTAMU_VERSION') ? self::KINSTA : null;
    }

    /**
     * @return string|null
     */
    private static function cloudways(): ?string
    {
        if (isset($_SERVER['cw_allowed_ip']) || Str::contains($_SERVER['DOCUMENT_ROOT'] ?? '', ['cloudwaysapps.com', 'cloudwaysstagingapps.com'])) {
            return self::CLOUDWAYS;
        }

        return null;
    }

    /**
     * @return string|null
     */
    private static function spinupWp(): ?string
    {
        return getenv('SPINUPWP_CACHE_PATH') || getenv('SPINUPWP_SITE') ? self::SPINUPWP : null;
    }

    /**
     * @return string|null
     */
    private static function pantheon(): ?string
    {
        if (isset($_ENV['PANTHEON_ENVIRONMENT']) || getenv('PANTHEON_ENVIRONMENT') || defined('PANTHEON_ENVIRONMENT')) {
            return self::PANTHEON;
        }

        return null;
    }

    /**
     * DreamPress, DreamHost's managed WordPress.
     *
     * @return string|null
     */
    private static function dreamHost(): ?string
    {
        return Str::startsWith(strtolower($_SERVER['DH_USER'] ?? ''), 'wp_') ? self::DREAMPRESS : null;
    }

    /**
     * @return string|null
     */
    private static function gridPane(): ?string
    {
        return defined('GRIDPANE') ? self::GRIDPANE : null;
    }

    /**
     * @return string|null
     */
    private static function wpmuDev(): ?string
    {
        return isset($_SERVER['WPMUDEV_HOSTED']) ? self::WPMUDEV : null;
    }

    /**
     * @return string|null
     */
    private static function rocketNet(): ?string
    {
        return defined('ROCKET_SITE_ID') || Str::contains(static::hostname(), 'onrocket.com') ? self::ROCKETNET : null;
    }

    /**
     * @return string|null
     */
    private static function wpx(): ?string
    {
        return Str::endsWith(static::hostname(), ['wpx.net', 'wpxhosting.com']) ? self::WPX : null;
    }

    /**
     * SiteGround's own check. Paths outside open_basedir can't be probed, so the hostname
     * is the only signal left there.
     *
     * @return string|null
     */
    private static function siteGround(): ?string
    {
        if (Str::contains(static::hostname(), 'siteground.eu')) {
            return self::SITEGROUND;
        }

        if (!empty(ini_get('open_basedir'))) {
            return null;
        }

        return @file_exists('/etc/yum.repos.d/baseos.repo') && @file_exists('/Z') ? self::SITEGROUND : null;
    }

    /**
     * @return string|null
     */
    private static function hostinger(): ?string
    {
        return !empty($_SERVER['H_PLATFORM']) ? self::HOSTINGER : null;
    }

    /**
     * @return string|null
     */
    private static function servebolt(): ?string
    {
        if (($_SERVER['SERVER_ADMIN'] ?? '') === 'support@servebolt.com' || Str::endsWith($_SERVER['HOSTNAME'] ?? '', ['servebolt.com', 'servebolt.cloud'])) {
            return self::SERVEBOLT;
        }

        return null;
    }

    /**
     * @return string|null
     */
    private static function seravo(): ?string
    {
        return Str::startsWith(static::abspath(), '/data/wordpress/htdocs/') ? self::SERAVO : null;
    }

    /**
     * @return string|null
     */
    private static function pressidium(): ?string
    {
        return defined('WP_NINUKIS_WP_NAME') ? self::PRESSIDIUM : null;
    }

    /**
     * The Savvii cache plugin is public; the WarpDrive variables come from the platform.
     *
     * @return string|null
     */
    private static function savvii(): ?string
    {
        if (getenv('WARPDRIVE_SYSTEM_NAME') || isset($_SERVER['WARPDRIVE_SYSTEM_NAME']) || isset($_SERVER['WARPDRIVE_API'])) {
            return self::SAVVII;
        }

        return null;
    }

    /**
     * @return string|null
     */
    private static function wpServeur(): ?string
    {
        return defined('DB_HOST') && Str::contains((string) DB_HOST, '.wpserveur.net') ? self::WPSERVEUR : null;
    }

    /**
     * @return string|null
     */
    private static function o2switch(): ?string
    {
        return defined('O2SWITCH_VARNISH_PURGE_KEY') ? self::O2SWITCH : null;
    }

    /**
     * @return string
     */
    private static function hostname(): string
    {
        return function_exists('gethostname') ? (string) gethostname() : '';
    }

    /**
     * @return string
     */
    private static function abspath(): string
    {
        return defined('ABSPATH') ? ABSPATH : '';
    }
}
