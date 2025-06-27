<?php

namespace Modular\Connector\Services;

use DateTime;
use DateTimeZone;
use Imagick;
use Modular\Connector\Facades\Manager;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Http\HttpUtils;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Config;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Storage;
use Modular\ConnectorDependencies\Illuminate\Support\Str;
use Modular\ConnectorDependencies\Symfony\Component\Console\SignalRegistry\SignalRegistry;

/**
 * Handles all functionality related to WordPress Core.
 */
class ManagerServer
{
    /**
     * @return string
     */
    public function connectorVersion()
    {
        return MODULAR_CONNECTOR_VERSION;
    }

    /**
     * @return string
     */
    public function phpVersion()
    {
        return PHP_VERSION;
    }

    /**
     * @return bool
     */
    public function useSsl()
    {
        return is_ssl();
    }

    /**
     * Detect if php is in safe mode
     *
     * @return bool
     */
    public function isSafeMode()
    {
        $value = ini_get('safe_mode');

        return !($value == 0 || strtolower($value) === 'off');
    }

    /**
     * Detect memory limit
     *
     * @return bool
     */
    public function memoryLimit()
    {
        return ini_get('memory_limit');
    }

    /**
     * Detect memory limit
     *
     * @return bool
     */
    public function disabledFunctions()
    {
        $functions = ini_get('disable_functions');
        $blacklist = ini_get('suhosin.executor.func.blacklist');

        $functions = array_merge(explode(',', $functions), explode(',', $blacklist));
        $functions = array_map(fn($function) => Str::lower(trim($function)), $functions);

        return array_filter($functions);
    }

    /**
     * Detect if shell is available
     *
     * @return bool
     */
    public function shellIsAvailable()
    {
        $requiredFunctions = ['escapeshellarg', 'proc_open', 'proc_get_status', 'proc_terminate', 'proc_close'];
        $disabledFunction = $this->disabledFunctions();

        return !$this->isSafeMode() && count(array_diff($requiredFunctions, $disabledFunction)) === count($requiredFunctions);
    }

    /**
     * @return false|float|null
     */
    public function getDiskSpace()
    {
        $diskFreeSpace = null;

        if (function_exists('disk_free_space')) {
            $diskFreeSpace = @disk_free_space(ABSPATH) ?? null;
        }

        return $diskFreeSpace;
    }

    /**
     * Check if the server is running on Windows or Unix
     *
     * @return bool
     */
    public function isUnix(): bool
    {
        return strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN';
    }

    /**
     * @return mixed|null
     */
    public function getWebServer()
    {
        return $_SERVER['SERVER_SOFTWARE'] ?? null;
    }

    /**
     * @return mixed|null
     */
    public function curlVersion()
    {
        if (!function_exists('curl_version')) {
            return null;
        }

        $curl = curl_version();

        return $curl['version'];
    }

    /**
     * @return mixed|null
     */
    public function getAutoloadedOptions()
    {
        if (!function_exists('wp_load_alloptions')) {
            return null;
        }

        $alloptions = wp_load_alloptions();

        $total_length = 0;

        foreach ($alloptions as $option_value) {
            if (is_array($option_value) || is_object($option_value)) {
                $option_value = maybe_serialize($option_value);
            }
            $total_length += strlen((string)$option_value);
        }

        return [
            'size' => $total_length,
            'count' => count($alloptions),
            'limit' => apply_filters('site_status_autoloaded_options_size_limit', 800000),
        ];
    }

    public function getDefaultTimezone()
    {
        if (!function_exists('date_default_timezone_get')) {
            return null;
        }

        return date_default_timezone_get();
    }

    /**
     * @return mixed|null
     */
    public function getServerTime()
    {
        return $_SERVER['REQUEST_TIME'] ?? null;
    }

    /**
     * @return bool
     */
    public function isPublic()
    {
        if (!function_exists('get_option')) {
            require_once untrailingslashit(ABSPATH) . '/wp-includes/option.php';
        }

        return get_option('blog_public') == 1;
    }

    /**
     * @return array[]
     */
    public function getDirectoriesData()
    {
        $directories = [
            'root' => [
                'path' => Storage::disk('core')->path(''),
                'exists' => file_exists(Storage::disk('core')->path('')),
                'writable' => wp_is_writable(Storage::disk('core')->path('')),
            ],
            'uploads' => [
                'path' => Storage::disk('uploads')->path(''),
                'exists' => file_exists(Storage::disk('uploads')->path('')),
                'writable' => wp_is_writable(Storage::disk('uploads')->path('')),
            ],
            'themes' => [
                'path' => Storage::disk('themes')->path(''),
                'exists' => file_exists(Storage::disk('themes')->path('')),
                'writable' => wp_is_writable(Storage::disk('themes')->path('')),
            ],
            'plugins' => [
                'path' => Storage::disk('plugins')->path(''),
                'exists' => file_exists(Storage::disk('plugins')->path('')),
                'writable' => wp_is_writable(Storage::disk('plugins')->path('')),
            ],
            'mu_plugins' => [
                'path' => Storage::disk('mu_plugins')->path(''),
                'exists' => file_exists(Storage::disk('mu_plugins')->path('')),
                'writable' => wp_is_writable(Storage::disk('mu_plugins')->path('')),
            ],
            'content' => [
                'path' => Storage::disk('content')->path(''),
                'exists' => file_exists(Storage::disk('content')->path('')),
                'writable' => wp_is_writable(Storage::disk('content')->path('')),
            ],
        ];

        if (function_exists('wp_get_font_dir')) {
            $dir = wp_get_font_dir();

            $directories['fonts'] = [
                'path' => $dir['basedir'],
                'exists' => file_exists($dir['basedir']),
                'writable' => wp_is_writable($dir['basedir']),
            ];
        }

        return $directories;
    }

    /**
     * @return array|null
     */
    public function getGDData()
    {
        if (!function_exists('gd_info')) {
            return null;
        }

        $gdData = gd_info();

        $gdImageFormats = [];
        $gdSupportedFormats = [
            'GIF Create' => 'GIF',
            'JPEG' => 'JPEG',
            'PNG' => 'PNG',
            'WebP' => 'WebP',
            'BMP' => 'BMP',
            'AVIF' => 'AVIF',
            'HEIF' => 'HEIF',
            'TIFF' => 'TIFF',
            'XPM' => 'XPM',
        ];

        foreach ($gdSupportedFormats as $formatKey => $format) {
            $index = $formatKey . ' Support';
            if (isset($gd[$index]) && $gd[$index]) {
                $gdImageFormats[] = $format;
            }
        }

        if (is_array($gdData)) {
            $gd = [
                'version' => $gdData['GD Version'],
                'formats' => $gdImageFormats,
            ];
        } else {
            $gd = null;
        }

        return $gd;
    }

    public function getImageMagickData()
    {
        if (!class_exists('Imagick')) {
            return null;
        }

        $imagick = new Imagick();

        $imagemagickVersion = $imagick->getVersion() ?? null;

        $formats = Imagick::queryFormats();
        $imagemagickFormats = !empty($formats) ? implode(', ', $formats) : null;

        if (_wp_image_editor_choose() === 'WP_Image_Editor_Imagick') {
            $imagemagickResources = [
                'area' => defined('imagick::RESOURCETYPE_AREA')
                    ? size_format($imagick->getResourceLimit(imagick::RESOURCETYPE_AREA))
                    : null,
                'disk' => defined('imagick::RESOURCETYPE_DISK')
                    ? $imagick->getResourceLimit(imagick::RESOURCETYPE_DISK)
                    : null,
                'file' => defined('imagick::RESOURCETYPE_FILE')
                    ? $imagick->getResourceLimit(imagick::RESOURCETYPE_FILE)
                    : null,
                'map' => defined('imagick::RESOURCETYPE_MAP')
                    ? size_format($imagick->getResourceLimit(imagick::RESOURCETYPE_MAP))
                    : null,
                'memory' => defined('imagick::RESOURCETYPE_MEMORY')
                    ? size_format($imagick->getResourceLimit(imagick::RESOURCETYPE_MEMORY))
                    : null,
                'thread' => defined('imagick::RESOURCETYPE_THREAD')
                    ? $imagick->getResourceLimit(imagick::RESOURCETYPE_THREAD)
                    : null,
                'time' => defined('imagick::RESOURCETYPE_TIME')
                    ? $imagick->getResourceLimit(imagick::RESOURCETYPE_TIME)
                    : null,
            ];
        } else {
            $imagemagickResources = null;
        }

        return [
            'version' => $imagemagickVersion,
            'formats' => $imagemagickFormats,
            'resources' => $imagemagickResources,
        ];
    }

    /**
     * @return array
     */
    public function getMediaData()
    {
        $imagickVersion = phpversion('imagick');

        $fileUploads = ini_get('file_uploads');
        $postMaxSize = ini_get('post_max_size');
        $uploadMaxFilesize = ini_get('upload_max_filesize');
        $maxFileUploads = ini_get('max_file_uploads');
        $effective = min(wp_convert_hr_to_bytes($postMaxSize), wp_convert_hr_to_bytes($uploadMaxFilesize));

        return [
            'imagick' => [
                'version' => $imagickVersion,
            ],
            'imageImagick' => $this->getImageMagickData(),
            'files' => [
                'fileUploads' => $fileUploads,
                'postMaxSize' => $postMaxSize,
                'uploadMaxFilesize' => $uploadMaxFilesize,
                'maxFileUploads' => $maxFileUploads,
                'effective' => size_format($effective),
            ],
            'gd' => $this->getGDData(),
        ];
    }

    /**
     * @return array
     */
    public function getConstantsData()
    {
        return [
            'ABSPATH' => defined('ABSPATH') ? ABSPATH : null,
            'WP_HOME' => defined('WP_HOME') ? WP_HOME : null,
            'WP_SITEURL' => defined('WP_SITEURL') ? WP_SITEURL : null,
            'WP_CONTENT_DIR' => defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : null,
            'WP_CONTENT_URL' => defined('WP_CONTENT_URL') ? WP_CONTENT_URL : null,
            'WP_PLUGIN_DIR' => defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : null,
            'WP_PLUGIN_URL' => defined('WP_PLUGIN_URL') ? WP_PLUGIN_URL : null,
            'WPMU_PLUGIN_DIR' => defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : null,
            'WPMU_PLUGIN_URL' => defined('WPMU_PLUGIN_URL') ? WPMU_PLUGIN_URL : null,
            'WP_MEMORY_LIMIT' => defined('WP_MEMORY_LIMIT') ? WP_MEMORY_LIMIT : null,
            'WP_MAX_MEMORY_LIMIT' => defined('WP_MAX_MEMORY_LIMIT') ? WP_MAX_MEMORY_LIMIT : null,
            'WP_DEBUG' => defined('WP_DEBUG') ? WP_DEBUG : null,
            'WP_DEBUG_LOG' => defined('WP_DEBUG_LOG') ? WP_DEBUG_LOG : null,
            'WP_DEBUG_DISPLAY' => defined('WP_DEBUG_DISPLAY') ? WP_DEBUG_DISPLAY : null,
            'SCRIPT_DEBUG' => defined('SCRIPT_DEBUG') ? SCRIPT_DEBUG : null,
            'WP_CACHE' => defined('WP_CACHE') ? WP_CACHE : null,
            'CONCATENATE_SCRIPTS' => defined('CONCATENATE_SCRIPTS') ? CONCATENATE_SCRIPTS : null,
            'COMPRESS_SCRIPTS' => defined('COMPRESS_SCRIPTS') ? COMPRESS_SCRIPTS : null,
            'COMPRESS_CSS' => defined('COMPRESS_CSS') ? COMPRESS_CSS : null,
            'WP_ENVIRONMENT_TYPE' => defined('WP_ENVIRONMENT_TYPE') ? WP_ENVIRONMENT_TYPE : null,
            'WP_DEVELOPMENT_MODE' => defined('WP_DEVELOPMENT_MODE') ? WP_DEVELOPMENT_MODE : null,
            'DISABLE_WP_CRON' => defined('DISABLE_WP_CRON') ? DISABLE_WP_CRON : null,
            'DB_CHARSET' => defined('DB_CHARSET') ? DB_CHARSET : null,
            'DB_COLLATE' => defined('DB_COLLATE') ? DB_COLLATE : null,
            'PHP_SAPI' => defined('PHP_SAPI') ? PHP_SAPI : null,

            'MODULAR_CONNECTOR_ENV' => defined('MODULAR_CONNECTOR_ENV') ? MODULAR_CONNECTOR_ENV : null,
            'MODULAR_CONNECTOR_LOOPBACK' => defined('MODULAR_CONNECTOR_LOOPBACK') ? MODULAR_CONNECTOR_LOOPBACK : null,
            'MODULAR_CONNECTOR_DEBUG' => defined('MODULAR_CONNECTOR_DEBUG') ? MODULAR_CONNECTOR_DEBUG : null,
            'MODULAR_CONNECTOR_DEBUG_SCHEDULE' => defined('MODULAR_CONNECTOR_DEBUG_SCHEDULE') ? MODULAR_CONNECTOR_DEBUG_SCHEDULE : null,
            'MODULAR_CONNECTOR_TIMEZONE' => defined('MODULAR_CONNECTOR_TIMEZONE') ? MODULAR_CONNECTOR_TIMEZONE : null,
            'MODULAR_CONNECTOR_BASENAME' => defined('MODULAR_CONNECTOR_BASENAME') ? MODULAR_CONNECTOR_BASENAME : null,
            'MODULAR_CONNECTOR_MU_BASENAME' => defined('MODULAR_CONNECTOR_MU_BASENAME') ? MODULAR_CONNECTOR_MU_BASENAME : null,
            'MODULAR_CONNECTOR_VERSION' => defined('MODULAR_CONNECTOR_VERSION') ? MODULAR_CONNECTOR_VERSION : null,
            'MODULAR_ARES_SCHEDULE_HOOK' => defined('MODULAR_ARES_SCHEDULE_HOOK') ? MODULAR_ARES_SCHEDULE_HOOK : null,
            'MODULAR_CONNECTOR_QUEUE_DRIVER' => defined('MODULAR_CONNECTOR_QUEUE_DRIVER') ? MODULAR_CONNECTOR_QUEUE_DRIVER : null,
            'MODULAR_CONNECTOR_CACHE_DRIVER' => defined('MODULAR_CONNECTOR_CACHE_DRIVER') ? MODULAR_CONNECTOR_CACHE_DRIVER : null,
            'MODULAR_LOG_CHANNEL' => defined('MODULAR_LOG_CHANNEL') ? MODULAR_LOG_CHANNEL : null,
            'MODULAR_CONNECTOR_LOG_LEVEL' => defined('MODULAR_CONNECTOR_LOG_LEVEL') ? MODULAR_CONNECTOR_LOG_LEVEL : null,
        ];
    }

    /**
     * Get server information
     *
     * @return array
     * @throws \DateMalformedStringException
     */
    public function information()
    {
        $date = new \DateTime('now', new DateTimeZone('UTC'));

        return [
            'connector_version' => $this->connectorVersion(),
            'php' => [
                'current' => $this->phpVersion(),
                'memory_limit' => $this->memoryLimit(),
                'safe_mode' => $this->isSafeMode(),
                'extensions' => [
                    'dom' => extension_loaded('dom'),
                    'exif' => extension_loaded('exif'),
                    'fileinfo' => extension_loaded('fileinfo'),
                    'hash' => extension_loaded('hash'),
                    'imagick' => extension_loaded('imagick'),
                    'json' => extension_loaded('json'),
                    'mbstring' => extension_loaded('mbstring'),
                    'libsodium' => extension_loaded('libsodium'),
                    'openssl' => extension_loaded('openssl'),
                    'pcre' => extension_loaded('pcre'),
                    'mod_xml' => extension_loaded('mod_xml'),
                    'filter' => extension_loaded('filter'),
                    'gd' => extension_loaded('gd'),
                    'iconv' => extension_loaded('iconv'),
                    'intl' => extension_loaded('intl'),
                    'mcrypt' => extension_loaded('mcrypt'),
                    'simplexml' => extension_loaded('simplexml'),
                    'xmlreader' => extension_loaded('xmlreader'),

                    'zlib' => extension_loaded('zlib'),
                    'mysql' => extension_loaded('mysql'),
                    'mysqli' => extension_loaded('mysqli'),
                    'pdo' => extension_loaded('pdo'),
                    'pdo_mysql' => extension_loaded('pdo_mysql'),
                    'open_ssl' => extension_loaded('openssl'),
                    'curl' => extension_loaded('curl'),
                    'zip' => extension_loaded('zip'),
                    'phar' => extension_loaded('phar'),
                    'pcntl' => extension_loaded('pcntl'),
                ],
                'shell' => $this->shellIsAvailable(),
                'signal' => SignalRegistry::isSupported(),
                'disabled_functions' => $this->disabledFunctions(),
            ],
            'database' => Manager::driver('database')->get(),
            'core' => Manager::driver('core')->get(),
            'site' => [
                'is_ssl' => $this->useSsl(),
                'is_multisite' => is_multisite(),
                'is_main_site' => is_main_site(),
                'base_url' => site_url(),
                'rest_url' => rest_url(),
                'home_url' => home_url(),
                'plugins_url' => plugins_url(),
                'timezone' => wp_timezone_string(),
                'is_public' => $this->isPublic(),
                'user_count' => count_users(), // TODO admin_user_count
                'is_mu_plugin' => HttpUtils::isMuPlugin(),
                'cache_driver' => Config::get('cache.default'),
                'queue_driver' => Config::get('queue.default'),
            ],
            'server' => [
                'uname' => function_exists('php_uname') ? php_uname() : null, // mode: a
                'hostname' => function_exists('php_uname') ? php_uname('n') : null,
                'disk_free_space' => $this->getDiskSpace(),
                'is_unix' => $this->isUnix(),
                'web_server' => $this->getWebServer(), // TODO split: web_server and web_server_version
                'curl_version' => $this->curlVersion(),
                'default_time_zone' => $this->getDefaultTimezone(),
                'current_time' => $date->format(DateTime::ATOM),
                'server_time' => $this->getServerTime(),
                'autoloaded_options' => $this->getAutoloadedOptions(),
            ],
            'media' => $this->getMediaData(),
            'directories' => $this->getDirectoriesData(),
            'constants' => $this->getConstantsData(),
        ];
    }

    /**
     * @return void
     * @deprecated Since 1.15.0. We need to remove this method when the minimum version of Modular is 1.15
     */
    public function logout()
    {
        if (!function_exists('wp_logout')) {
            include_once ABSPATH . '/wp-includes/pluggable.php';
        }

        try {
            // Emulate the logout process without do_action( 'wp_logout', $user_id );
            wp_set_current_user(0);
        } catch (\Throwable $e) {
            // Silence is golden
        }
    }

    /**
     * Force the maintenance mode on or off.
     *
     * @param $enable
     * @param $indefinite
     * @return void
     */
    public function maintenanceMode($enable = true, $indefinite = false)
    {
        global $wp_filesystem;

        if (!$wp_filesystem) {
            require_once ABSPATH . 'wp-admin/includes/file.php';

            WP_Filesystem();
        }

        $file = $wp_filesystem->abspath() . '.maintenance';

        if ($enable) {
            // Create maintenance file to signal that we are upgrading.
            $maintenanceString = sprintf(
                '<?php $upgrading = (!isset($_GET["origin"], $_GET["type"], $_GET["mrid"]) || $_GET["origin"] !== "mo") ? %s : 0; ?>',
                $indefinite ? 'time()' : time()
            );

            $wp_filesystem->delete($file);
            $wp_filesystem->put_contents($file, $maintenanceString, FS_CHMOD_FILE);
        } elseif (!$enable && $wp_filesystem->exists($file)) {
            $wp_filesystem->delete($file);
        }
    }
}
