<?php

namespace Modular\Connector\Helper;

use Modular\ConnectorDependencies\Illuminate\Support\Facades\File;
use Modular\ConnectorDependencies\Illuminate\Support\Str;

use function Modular\ConnectorDependencies\storage_path;

/**
 * Names the storage artifacts that must not be readable over HTTP.
 *
 * The storage folders ship an .htaccess, which nginx never reads, so a deterministic
 * name leaves their contents fetchable. Names therefore carry a token derived from the
 * secrets already present in wp-config.php: unguessable without them, stable across
 * requests, and free of any option read or write.
 *
 * Each artifact derives its own token from a distinct purpose, so learning one name
 * never reveals the others.
 */
class PrivateStorage
{
    /**
     * Salts first, keys as a backup. Any of them is enough on its own; concatenating
     * whatever exists just avoids depending on a particular wp-config layout.
     */
    private const SECRETS = [
        'AUTH_SALT',
        'SECURE_AUTH_SALT',
        'LOGGED_IN_SALT',
        'NONCE_SALT',
        'AUTH_KEY',
        'SECURE_AUTH_KEY',
        'LOGGED_IN_KEY',
        'NONCE_KEY',
    ];

    /**
     * The placeholder wp-config-sample.php ships with. Hashing it would hand every
     * install that never rotated its salts the exact same name.
     */
    private const PLACEHOLDER = 'put your unique phrase here';

    private const LOG_BASENAME = 'modular-connector';

    /**
     * @var string|null
     */
    private static $secret = null;

    /**
     * @var array
     */
    private static $tokens = [];

    /**
     * @param string $purpose
     * @return string
     */
    public static function token(string $purpose): string
    {
        if (isset(static::$tokens[$purpose])) {
            return static::$tokens[$purpose];
        }

        return static::$tokens[$purpose] = substr(
            hash_hmac('sha256', 'modular-connector/' . $purpose, static::secret()),
            0,
            32
        );
    }

    /**
     * @return string
     */
    private static function secret(): string
    {
        if (static::$secret !== null) {
            return static::$secret;
        }

        $secret = '';

        foreach (self::SECRETS as $name) {
            if (!defined($name)) {
                continue;
            }

            $value = constant($name);

            if (!is_string($value) || $value === '' || Str::contains($value, self::PLACEHOLDER)) {
                continue;
            }

            $secret .= $value;
        }

        if ($secret === '') {
            // No usable salt. The database credentials are never public either, and
            // they are defined on every install that got this far.
            $secret = (defined('DB_PASSWORD') ? DB_PASSWORD : '')
                . (defined('DB_NAME') ? DB_NAME : '')
                . (defined('DB_USER') ? DB_USER : '')
                . ABSPATH;
        }

        return static::$secret = $secret;
    }

    /**
     * Name of a storage subfolder, token included.
     *
     * @param string $name
     * @return string
     */
    public static function folderName(string $name): string
    {
        return $name . '-' . static::token($name);
    }

    /**
     * Absolute path of a storage subfolder. Used to build the disk roots, so every
     * `Storage::disk(...)` call site keeps working untouched.
     *
     * @param string $name
     * @return string
     */
    public static function folderPath(string $name): string
    {
        return MODULAR_CONNECTOR_STORAGE_PATH . DIRECTORY_SEPARATOR . static::folderName($name);
    }

    /**
     * Folders that once held $name and no longer do: the pre-token one, and whatever a
     * salt rotation orphaned. Nothing reads them any more and they stay readable over
     * HTTP, so the cleanup command removes them; the caller decides what is old enough.
     *
     * @param string $name
     * @return array absolute paths
     */
    public static function staleFolders(string $name): array
    {
        $current = static::folderName($name);

        // No dash before the wildcard: the pre-token folders were named bare.
        $paths = File::glob(
            MODULAR_CONNECTOR_STORAGE_PATH . DIRECTORY_SEPARATOR . $name . '*',
            GLOB_ONLYDIR
        );

        $paths = is_array($paths) ? $paths : [];

        // Backups are the one artifact whose pre-token folder sat outside the storage
        // root, so the glob above cannot see it.
        if ($name === 'backups' && defined('MODULAR_CONNECTOR_BACKUPS_PATH')) {
            $paths[] = MODULAR_CONNECTOR_BACKUPS_PATH;
        }

        return array_values(array_filter(
            $paths,
            fn($path) => File::isDirectory($path) && basename($path) !== $current
        ));
    }

    /**
     * Base path handed to Monolog. The daily channel appends `-YYYY-MM-DD` after the
     * token, so its rotation glob only ever matches this install's own files.
     *
     * @return string
     */
    public static function logPath(): string
    {
        return storage_path('logs/' . self::LOG_BASENAME . '-' . static::token('logs') . '.log');
    }

    /**
     * Glob matching every log this plugin may have written, past or present.
     *
     * @return string
     */
    public static function logGlob(): string
    {
        // No dash before the wildcard: the legacy single channel wrote a bare
        // `modular-connector.log`, which has to be swept too.
        return storage_path('logs/' . self::LOG_BASENAME . '*.log');
    }

    /**
     * Whether an artifact was written under a name this install no longer uses: a
     * pre-token one, or a token from salts that have since been rotated. Both stay
     * fetchable on nginx and nothing else would ever clean them up.
     *
     * @param string $path
     * @param string $current basename this install currently writes to
     * @return bool
     */
    public static function isForeign(string $path, string $current): bool
    {
        return !Str::startsWith(basename($path), $current);
    }
}
