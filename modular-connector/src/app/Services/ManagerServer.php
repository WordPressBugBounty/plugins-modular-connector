<?php

namespace Modular\Connector\Services;

use Imagick;
use Modular\Connector\Facades\Manager;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Http\HttpUtils;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\ServerSetup;
use Modular\ConnectorDependencies\Carbon\Carbon;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Config;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\DB;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Storage;
use Modular\ConnectorDependencies\Illuminate\Support\Str;

/**
 * Handles all functionality related to WordPress Core.
 */
class ManagerServer
{
    /**
     * Safely execute a callable and return default value on failure
     *
     * @param callable $callback
     * @param mixed $default
     * @return mixed
     */
    private function safe(callable $callback, $default = null)
    {
        try {
            return $callback();
        } catch (\Throwable $e) {
            return $default;
        }
    }

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

        return count(array_diff($requiredFunctions, $disabledFunction)) === count($requiredFunctions);
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
    private function getAutoloadedOptions()
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
    private function getDirectoriesData()
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
    private function getGDData()
    {
        if (!function_exists('gd_info')) {
            return null;
        }

        $gdData = $this->safe(fn() => gd_info());

        if (!is_array($gdData)) {
            return null;
        }

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
            if (isset($gdData[$index]) && $gdData[$index]) {
                $gdImageFormats[] = $format;
            }
        }

        return [
            'version' => $gdData['GD Version'] ?? null,
            'formats' => $gdImageFormats,
        ];
    }

    private function getImageMagickData()
    {
        if (!class_exists('Imagick')) {
            return null;
        }

        $imagick = $this->safe(fn() => new Imagick());
        if (!$imagick) {
            return null;
        }

        $imagemagickVersion = $this->safe(fn() => $imagick->getVersion());

        $formats = $this->safe(fn() => Imagick::queryFormats(), []);
        $imagemagickFormats = !empty($formats) ? implode(', ', $formats) : null;

        if (_wp_image_editor_choose() === 'WP_Image_Editor_Imagick') {
            $imagemagickResources = [
                'area' => defined('imagick::RESOURCETYPE_AREA')
                    ? $this->safe(fn() => size_format($imagick->getResourceLimit(imagick::RESOURCETYPE_AREA)))
                    : null,
                'disk' => defined('imagick::RESOURCETYPE_DISK')
                    ? $this->safe(fn() => $imagick->getResourceLimit(imagick::RESOURCETYPE_DISK))
                    : null,
                'file' => defined('imagick::RESOURCETYPE_FILE')
                    ? $this->safe(fn() => $imagick->getResourceLimit(imagick::RESOURCETYPE_FILE))
                    : null,
                'map' => defined('imagick::RESOURCETYPE_MAP')
                    ? $this->safe(fn() => size_format($imagick->getResourceLimit(imagick::RESOURCETYPE_MAP)))
                    : null,
                'memory' => defined('imagick::RESOURCETYPE_MEMORY')
                    ? $this->safe(fn() => size_format($imagick->getResourceLimit(imagick::RESOURCETYPE_MEMORY)))
                    : null,
                'thread' => defined('imagick::RESOURCETYPE_THREAD')
                    ? $this->safe(fn() => $imagick->getResourceLimit(imagick::RESOURCETYPE_THREAD))
                    : null,
                'time' => defined('imagick::RESOURCETYPE_TIME')
                    ? $this->safe(fn() => $imagick->getResourceLimit(imagick::RESOURCETYPE_TIME))
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
    private function getMediaData()
    {
        $imagickVersion = function_exists('phpversion') ? phpversion('imagick') : null;

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
            'yearmonth_folders' => (bool)get_option('uploads_use_yearmonth_folders', 1),
        ];
    }

    /**
     * @return array
     */
    private function getConstantsData()
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

            // Filesystem restrictions
            'DISALLOW_FILE_MODS' => defined('DISALLOW_FILE_MODS') ? DISALLOW_FILE_MODS : null,
            'DISALLOW_FILE_EDIT' => defined('DISALLOW_FILE_EDIT') ? DISALLOW_FILE_EDIT : null,

            // Auto-update controls
            'AUTOMATIC_UPDATER_DISABLED' => defined('AUTOMATIC_UPDATER_DISABLED') ? AUTOMATIC_UPDATER_DISABLED : null,
            'WP_AUTO_UPDATE_CORE' => defined('WP_AUTO_UPDATE_CORE') ? WP_AUTO_UPDATE_CORE : null,

            // Filesystem method and permissions
            'FS_METHOD' => defined('FS_METHOD') ? FS_METHOD : null,
            'FS_CHMOD_FILE' => defined('FS_CHMOD_FILE') ? FS_CHMOD_FILE : null,
            'FS_CHMOD_DIR' => defined('FS_CHMOD_DIR') ? FS_CHMOD_DIR : null,
            'FTP_BASE' => defined('FTP_BASE') ? FTP_BASE : null,
            'FTP_CONTENT_DIR' => defined('FTP_CONTENT_DIR') ? FTP_CONTENT_DIR : null,
            'FTP_PLUGIN_DIR' => defined('FTP_PLUGIN_DIR') ? FTP_PLUGIN_DIR : null,

            // Temp and paths
            'WP_TEMP_DIR' => defined('WP_TEMP_DIR') ? WP_TEMP_DIR : null,

            // SSL
            'FORCE_SSL_ADMIN' => defined('FORCE_SSL_ADMIN') ? FORCE_SSL_ADMIN : null,

            // Security
            'ALLOW_UNFILTERED_UPLOADS' => defined('ALLOW_UNFILTERED_UPLOADS') ? ALLOW_UNFILTERED_UPLOADS : null,
            'DISALLOW_UNFILTERED_HTML' => defined('DISALLOW_UNFILTERED_HTML') ? DISALLOW_UNFILTERED_HTML : null,

            // Cron
            'WP_CRON_LOCK_TIMEOUT' => defined('WP_CRON_LOCK_TIMEOUT') ? WP_CRON_LOCK_TIMEOUT : null,

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
     * Get user count with role distribution.
     *
     * Emulates WordPress count_users() but optimized with a single query.
     * Only counts registered roles (like WordPress strategy 'time').
     *
     * @return array
     */
    private function getUserCountData(): array
    {
        $prefix = DB::getTablePrefix();

        // In multisite, get the correct prefix for current blog
        if (is_multisite()) {
            global $wpdb;

            $prefix = $wpdb->get_blog_prefix();
        }

        $metaKey = $prefix . 'capabilities';

        // Single query with JOIN to ensure only existing users are counted
        $results = DB::table('usermeta')
            ->join('users', 'usermeta.user_id', '=', 'users.ID')
            ->select(DB::raw('meta_value, COUNT(*) as count'))
            ->where('meta_key', $metaKey)
            ->groupBy('meta_value')
            ->get();

        // Initialize with all registered roles (like WordPress does)
        $registeredRoles = array_keys(wp_roles()->get_names());
        $availRoles = array_fill_keys($registeredRoles, 0);
        $availRoles['none'] = 0;  // For users without roles

        $totalUsers = 0;

        foreach ($results as $row) {
            $totalUsers += (int)$row->count;
            $capabilities = maybe_unserialize($row->meta_value);

            // Users without roles (a:0:{})
            if (!is_array($capabilities) || empty($capabilities)) {
                $availRoles['none'] += (int)$row->count;
                continue;
            }

            // Only count registered roles (like WordPress strategy 'time')
            foreach ($capabilities as $role => $enabled) {
                if ($enabled && isset($availRoles[$role])) {
                    $availRoles[$role] += (int)$row->count;
                }
            }
        }

        return [
            'total_users' => $totalUsers,
            'avail_roles' => $availRoles,
        ];
    }

    /**
     * Get PHP runtime configuration.
     *
     * @return array
     */
    private function getRuntimeData(): array
    {
        $uploadTmpDir = ini_get('upload_tmp_dir') ?: sys_get_temp_dir();

        return [
            'max_execution_time' => (int)ini_get('max_execution_time'),
            'max_input_time' => (int)ini_get('max_input_time'),
            'max_input_vars' => (int)ini_get('max_input_vars'),
            'upload_tmp_dir' => $uploadTmpDir,
            'upload_tmp_dir_writable' => function_exists('wp_is_writable')
                ? wp_is_writable($uploadTmpDir)
                : is_writable($uploadTmpDir),
            'opcache' => [
                'enabled' => function_exists('opcache_get_status')
                    && !empty(@opcache_get_status(false)),
                'revalidate_freq' => $this->safe(fn() => ini_get('opcache.revalidate_freq')),
                'validate_timestamps' => $this->safe(fn() => (bool)ini_get('opcache.validate_timestamps')),
                'memory_consumption' => $this->safe(fn() => ini_get('opcache.memory_consumption')),
                'max_accelerated_files' => $this->safe(fn() => (int)ini_get('opcache.max_accelerated_files')),
                'interned_strings_buffer' => $this->safe(fn() => ini_get('opcache.interned_strings_buffer')),
                'jit' => $this->safe(fn() => ini_get('opcache.jit')),
            ],
        ];
    }

    /**
     * Detect reverse proxy and CDN infrastructure.
     *
     * @return array
     */
    private function getProxyData(): array
    {
        return [
            'is_proxied' => !empty($_SERVER['HTTP_X_FORWARDED_FOR'])
                || !empty($_SERVER['HTTP_X_REAL_IP'])
                || !empty($_SERVER['HTTP_CF_CONNECTING_IP']),
            'cloudflare' => [
                'detected' => !empty($_SERVER['HTTP_CF_RAY']),
                'country' => $_SERVER['HTTP_CF_IPCOUNTRY'] ?? null,
                'ray_id' => $_SERVER['HTTP_CF_RAY'] ?? null,
            ],
            'forwarded' => [
                'for' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
                'proto' => $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null,
                'host' => $_SERVER['HTTP_X_FORWARDED_HOST'] ?? null,
            ],
            'real_ip' => $_SERVER['HTTP_X_REAL_IP'] ?? null,
        ];
    }

    /**
     * Detect WordPress object cache configuration.
     *
     * @return array
     */
    private function getObjectCacheData(): array
    {
        return [
            'external' => function_exists('wp_using_ext_object_cache')
                ? wp_using_ext_object_cache()
                : false,
            'drop_in_exists' => Storage::disk('content')->exists('object-cache.php'),
            'redis_available' => extension_loaded('redis'),
            'memcached_available' => extension_loaded('memcached') || extension_loaded('memcache'),
            'apcu_available' => extension_loaded('apcu'),
        ];
    }

    /**
     * Get WP-Cron health information.
     *
     * @return array
     */
    private function getCronHealthData(): array
    {
        $crons = function_exists('_get_cron_array') ? _get_cron_array() : [];
        $cronLock = function_exists('get_transient')
            ? (int)get_transient('doing_cron')
            : 0;

        $nextCron = null;
        if (is_array($crons) && !empty($crons)) {
            $nextCron = min(array_keys($crons));
        }

        $eventCount = 0;
        if (is_array($crons)) {
            foreach ($crons as $timestamp => $hooks) {
                if (is_array($hooks)) {
                    foreach ($hooks as $hook => $events) {
                        $eventCount += is_array($events) ? count($events) : 0;
                    }
                }
            }
        }

        return [
            'disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
            'scheduled_events' => $eventCount,
            'cron_lock_active' => $cronLock > 0,
            'cron_lock_timestamp' => $cronLock > 0 ? $cronLock : null,
            'next_scheduled' => $nextCron ? Carbon::createFromTimestamp($nextCron)->format(Carbon::ATOM) : null,
            'cron_lock_timeout' => defined('WP_CRON_LOCK_TIMEOUT') ? WP_CRON_LOCK_TIMEOUT : 60,
        ];
    }

    /**
     * Get PHP runtime and extensions data.
     *
     * @return array
     */
    private function getPhpData(): array
    {
        return [
            'current' => $this->phpVersion(),
            'memory_limit' => $this->memoryLimit(),
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
                'mysqli' => extension_loaded('mysqli'),
                'pdo' => extension_loaded('pdo'),
                'pdo_mysql' => extension_loaded('pdo_mysql'),
                'curl' => extension_loaded('curl'),
                'zip' => extension_loaded('zip'),
                'phar' => extension_loaded('phar'),
                'pcntl' => extension_loaded('pcntl'),
                'posix' => extension_loaded('posix'),
            ],
            'hash_algos' => $this->safe(function () {
                $available = hash_algos();
                return [
                    'sha256' => in_array('sha256', $available),
                    'xxh128' => in_array('xxh128', $available),
                    'md5' => in_array('md5', $available),
                ];
            }, ['sha256' => false, 'xxh128' => false, 'md5' => false]),
            'shell' => $this->shellIsAvailable(),
            'signal' => ServerSetup::supportsAsyncSignals(),
            'disabled_functions' => $this->disabledFunctions(),
            'runtime' => $this->getRuntimeData(),
        ];
    }

    /**
     * Get WordPress site configuration data.
     *
     * @return array
     */
    private function getSiteData(): array
    {
        return [
            'is_ssl' => $this->useSsl(),
            'is_multisite' => is_multisite(),
            'is_main_site' => is_main_site(),
            'base_url' => site_url(),
            'rest_url' => rest_url(),
            'home_url' => home_url(),
            'plugins_url' => plugins_url(),
            'timezone' => wp_timezone_string(),
            'is_public' => $this->isPublic(),
            'user_count' => $this->safe(fn() => $this->getUserCountData(), count_users()),
            'is_mu_plugin' => HttpUtils::isMuPlugin(),
            'cache_driver' => Config::get('cache.default'),
            'queue_driver' => Config::get('queue.default'),
            'proxy' => $this->getProxyData(),
            'object_cache' => $this->getObjectCacheData(),
            'cron' => $this->getCronHealthData(),
        ];
    }

    /**
     * Get server environment data.
     *
     * @return array
     */
    private function getServerData(): array
    {
        $date = Carbon::now('UTC');

        return [
            'uname' => function_exists('php_uname') ? php_uname() : null,
            'hostname' => function_exists('php_uname') ? php_uname('n') : null,
            'disk_free_space' => $this->getDiskSpace(),
            'is_unix' => $this->isUnix(),
            'web_server' => $this->getWebServer(),
            'curl_version' => $this->curlVersion(),
            'default_time_zone' => $this->getDefaultTimezone(),
            'current_time' => $date->format(Carbon::ATOM),
            'server_time' => $this->getServerTime(),
            'autoloaded_options' => $this->getAutoloadedOptions(),
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
        return [
            'connector_version' => $this->connectorVersion(),
            'php' => $this->getPhpData(),
            'database' => Manager::driver('database')->get(),
            'core' => Manager::driver('core')->get(),
            'site' => $this->getSiteData(),
            'server' => $this->getServerData(),
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
            wp_set_current_user(0);
        } catch (\Throwable $e) {
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
            // Create maintenance file with strict validation for Modular communications
            // Uses centralized logic from HttpUtils::generateMaintenance()
            $maintenanceString = HttpUtils::generateMaintenance($indefinite);

            $wp_filesystem->delete($file);
            $wp_filesystem->put_contents($file, $maintenanceString, FS_CHMOD_FILE);
        } elseif (!$enable && $wp_filesystem->exists($file)) {
            $wp_filesystem->delete($file);
        }
    }
}
