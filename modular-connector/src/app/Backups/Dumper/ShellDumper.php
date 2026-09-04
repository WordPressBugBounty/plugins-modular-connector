<?php

namespace Modular\Connector\Backups\Dumper;

use Modular\Connector\Facades\Manager;
use Modular\ConnectorDependencies\Spatie\DbDumper\Databases\MariaDb;
use Modular\ConnectorDependencies\Spatie\DbDumper\Databases\MySql;
use Modular\ConnectorDependencies\Symfony\Component\Process\Process;

/**
 * Dumps the database through the native client binary.
 *
 * Requires PHP 8.0: `spatie/db-dumper` 3.x uses union types and `static` return types, which
 * are parse errors on 7.4, so its classes must never be autoloaded there. `ManagerDatabase`
 * guards the call rather than this class, so nothing here is reached on an older runtime.
 */
class ShellDumper
{
    public const BINARY_MARIADB = 'mariadb-dump';
    public const BINARY_MYSQL = 'mysqldump';

    /**
     * MariaDB release that introduced the `mariadb-dump` binary. Before it only `mysqldump`
     * exists; from 11.0 only `mariadb-dump` does.
     */
    private const MARIADB_DUMP_SINCE = '10.5';

    /**
     * Seconds allowed for the `--version` probe. It answers instantly when the binary
     * exists; the timeout only bounds pathological shells.
     */
    private const DETECT_TIMEOUT = 10;

    /**
     * Opening bytes of MariaDB's sandbox-mode header. Only the version gate is matched, so a
     * change in the wording after it does not stop the header from being recognised.
     */
    private const SANDBOX_PREFIX = '/*!999999';

    /**
     * Bytes read while looking for the header. Comfortably longer than it, short enough that
     * a dump whose first line is a data row is not pulled into memory.
     */
    private const SANDBOX_READ_LIMIT = 128;

    /**
     * @param string $path
     * @param array $connection
     * @param array $excluded
     * @param array|null $only when set, dump only these tables ($excluded is ignored)
     * @param string|null $binary binary already resolved during the plan phase; null re-derives it
     * @return void
     */
    public static function dump(string $path, array $connection, array $excluded, ?array $only = null, ?string $binary = null)
    {
        if ($binary === null) {
            $binary = static::preferredBinary();
        }

        $dumper = $binary === self::BINARY_MARIADB ? MariaDb::create() : MySql::create();

        static::run($dumper, $path, $connection, $excluded, $only, $binary);

        if ($binary === self::BINARY_MARIADB) {
            static::neutralizeSandboxHeader($path);
        }
    }

    /**
     * Binary this server should be dumped with, by engine and version.
     *
     * @return string
     */
    public static function preferredBinary(): string
    {
        $database = Manager::driver('database');

        $isMariaDb = $database->engine() === 'MariaDB';
        $version = $database->server();

        $useMariaDbBinary = $isMariaDb
            && !empty($version)
            && version_compare($version, self::MARIADB_DUMP_SINCE, '>=');

        return $useMariaDbBinary ? self::BINARY_MARIADB : self::BINARY_MYSQL;
    }

    /**
     * Memoized `--version` banners, keyed by binary, one probe per PHP process.
     *
     * @var array
     */
    protected static array $banners = [];

    /**
     * Hosts for which the client talks over a UNIX socket instead of TCP.
     */
    private const LOCAL_HOSTS = ['', 'localhost'];

    /**
     * Socket the dump binary must connect through, or null to let it use the host.
     *
     * `localhost` is not an address for MySQL clients: it selects the UNIX socket and
     * ignores any port. The binary then falls back to the socket path it was compiled with
     * (`/tmp/mysqld.sock`), which on shared hosting rarely matches the one PHP was built to
     * use — the dump dies with `2002: Can't connect to local MySQL server` even though
     * WordPress is happily connected. Asking the live connection where its socket actually
     * is costs one query on the pool Laravel already holds and settles it.
     *
     * @param array $connection
     * @return string|null
     */
    protected static function resolveSocket(array $connection): ?string
    {
        if (!empty($connection['socket'])) {
            return $connection['socket'];
        }

        $host = strtolower(trim((string)$connection['host']));

        if (!in_array($host, self::LOCAL_HOSTS, true)) {
            return null;
        }

        $socket = Manager::driver('database')->getMySQLVar('socket');

        if (!empty($socket)) {
            return $socket;
        }

        // The server did not report one (or the query was denied): fall back to whatever
        // socket this PHP build talks to, which is by definition a working path.
        foreach (['mysqli.default_socket', 'pdo_mysql.default_socket'] as $setting) {
            $socket = ini_get($setting);

            if (!empty($socket)) {
                return $socket;
            }
        }

        return null;
    }

    /**
     * `--version` banner of a dump binary, memoized per process. Empty when it cannot run.
     *
     * The binary's own family is what decides which flags are safe — hosts routinely ship a
     * MariaDB client under the `mysqldump` name, and the server engine says nothing about it.
     *
     * @param string $binary
     * @return string
     */
    protected static function clientBanner(string $binary): string
    {
        if (isset(static::$banners[$binary])) {
            return static::$banners[$binary];
        }

        $banner = '';

        try {
            $process = new Process([$binary, '--version']);
            $process->setTimeout(self::DETECT_TIMEOUT);
            $process->run();

            if ($process->isSuccessful()) {
                $banner = $process->getOutput();
            }
        } catch (\Throwable $e) {
            // Unprobeable client: callers fall back to the safest flag set.
        }

        return static::$banners[$binary] = $banner;
    }

    /**
     * Whether the dump client belongs to the MariaDB family.
     *
     * @param string $binary
     * @return bool
     */
    protected static function clientIsMariaDb(string $binary): bool
    {
        return $binary === self::BINARY_MARIADB
            || stripos(static::clientBanner($binary), 'mariadb') !== false;
    }

    /**
     * Whether the dump client understands `--column-statistics`.
     *
     * Only Oracle's mysqldump 8.0.2+ does — MariaDB's client and MySQL 5.x clients reject
     * it no matter what server they talk to. Old clients print their real release in
     * `Distrib` (`Ver 10.13 Distrib 5.7.44`); from 8.0 the release is the `Ver` itself
     * (`Ver 8.0.36`).
     *
     * @param string $binary
     * @return bool
     */
    protected static function clientSupportsColumnStatistics(string $binary): bool
    {
        if (static::clientIsMariaDb($binary)) {
            return false;
        }

        $banner = static::clientBanner($binary);

        if (preg_match('/Distrib\s+(\d+)\./i', $banner, $matches) === 1) {
            return (int)$matches[1] >= 8;
        }

        if (preg_match('/Ver\s+(\d+)\./i', $banner, $matches) === 1) {
            return (int)$matches[1] >= 8;
        }

        return false;
    }

    /**
     * Probe which dump binary actually runs on this host, or null when none does.
     *
     * `mysqldump` is probed as a fallback on MariaDB 10.5-10.x, where both binaries ship;
     * from 11.0 only `mariadb-dump` exists, so the fallback probe simply fails there too.
     *
     * @return string|null
     */
    public static function detectBinary(): ?string
    {
        $preferred = static::preferredBinary();

        $candidates = $preferred === self::BINARY_MARIADB
            ? [self::BINARY_MARIADB, self::BINARY_MYSQL]
            : [self::BINARY_MYSQL];

        foreach ($candidates as $binary) {
            try {
                $process = new Process([$binary, '--version']);
                $process->setTimeout(self::DETECT_TIMEOUT);
                $process->run();

                if ($process->isSuccessful()) {
                    return $binary;
                }
            } catch (\Throwable $e) {
                // Missing binary or blocked exec: try the next candidate.
            }
        }

        return null;
    }

    /**
     * Comment out MariaDB's sandbox-mode header, in place.
     *
     * `mariadb-dump` opens every dump with `/*!999999\- enable the sandbox mode *​/`, which
     * CLI clients reduce to `\-` and try to run as an internal command, so restoring one of
     * our dumps by hand fails with "Unknown command '\-'". Our own restore is unaffected: it
     * goes through PDO, where the server reads a comment gated on version 99.99.99.
     *
     * The header is overwritten with a comment of exactly the same length so the rest of the
     * file never moves — rewriting a multi-GB dump to drop one line would cost more than the
     * dump itself on the slow disks this runs on.
     *
     * `MariaDb::withoutSandboxMode()` is deliberately not used: it appends `|tail +2` to the
     * command, and a pipeline reports the exit code of its last element. A dump that died
     * halfway would then look successful, pass the library's non-empty check on its partial
     * output, and be stored as a truncated backup with nothing logged.
     *
     * @param string $path
     * @return void
     */
    protected static function neutralizeSandboxHeader(string $path): void
    {
        $handle = @fopen($path, 'r+');

        if ($handle === false) {
            return;
        }

        try {
            $line = fgets($handle, self::SANDBOX_READ_LIMIT);

            if ($line === false || strpos($line, self::SANDBOX_PREFIX) !== 0) {
                return;
            }

            $length = strlen(rtrim($line, "\r\n"));
            $comment = '-- sandbox mode header neutralized';

            if ($length < strlen($comment)) {
                return;
            }

            rewind($handle);
            fwrite($handle, str_pad($comment, $length));
        } finally {
            fclose($handle);
        }
    }

    /**
     * Configure the dumper with the connection details and write the dump.
     *
     * @param MySql $dumper
     * @param string $path
     * @param array $connection
     * @param array $excluded
     * @param array|null $only
     * @param string $binary
     * @return void
     */
    protected static function run(MySql $dumper, string $path, array $connection, array $excluded, ?array $only, string $binary): void
    {
        $port = $connection['port'];
        $socket = $connection['socket'];

        $dumper = $dumper
            ->setHost($connection['host'])
            ->setDbName($connection['database'])
            ->setUserName($connection['username'])
            ->setPassword($connection['password']);

        // The library forbids combining both lists, so they are mutually exclusive here too.
        if ($only !== null) {
            $dumper = $dumper->includeTables($only);
        } else {
            $dumper = $dumper->excludeTables($excluded);
        }

        if (!empty($port) && is_int($port)) {
            $dumper = $dumper->setPort($port);
        }

        $socket = static::resolveSocket($connection);

        if (!empty($socket)) {
            $dumper = $dumper->setSocket($socket);
        }

        // The client refuses packets above its own buffer (24M by default), so a single
        // oversized serialized row aborts the whole dump. Session-scoped, needs no privileges.
        $dumper = $dumper->addExtraOption('--max-allowed-packet=64M');

        // `--column-statistics=0` only exists in Oracle's mysqldump 8.0.2+. Whether to send
        // it depends on the CLIENT binary, not the server engine: hosts routinely pair a
        // MySQL 8 server with a MariaDB (or 5.x) mysqldump, which rejects the flag outright.
        if (static::clientSupportsColumnStatistics($binary)) {
            $dumper = $dumper->doNotUseColumnStatistics();
        }

        // MariaDB clients verify the server certificate from 11.4 on, and hosts serving TLS
        // with a self-signed certificate then fail with `2026: TLS/SSL error`. PHP's own
        // driver does not verify it either, so the dump only has to match the connection
        // WordPress already trusts. Encryption itself stays on; only the check is dropped,
        // and the flag is inert when the server speaks no TLS.
        if (static::clientIsMariaDb($binary)) {
            $dumper = $dumper->addExtraOption('--ssl-verify-server-cert=0');
        }

        $dumper->dumpToFile($path);
    }
}
