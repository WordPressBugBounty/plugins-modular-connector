<?php

namespace Modular\Connector\Backups\Dumper;

use Modular\ConnectorDependencies\Ifsnop\Mysqldump\Mysqldump;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Config;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\DB;

class PHPDumper
{
    /**
     * Seconds allowed to open the dump connection.
     */
    private const CONNECT_TIMEOUT = 10;

    /**
     * Server-side write timeout for the dump session, seconds. Long enough to ride out the
     * write stalls of a ~1MB/s disk between two unbuffered reads, short enough that a hung
     * dump cannot hold its connection slot indefinitely on hosts where slots are scarce.
     */
    private const NET_WRITE_TIMEOUT = 300;

    /**
     * @var string
     */
    protected string $charset = Mysqldump::UTF8MB4;

    /**
     * @var string
     */
    protected string $host = 'localhost';

    /**
     * @var ?int
     */
    protected ?int $port = null;

    /**
     * @var ?string
     */
    protected ?string $socket = null;

    /**
     * @var string
     */
    protected string $database;

    /**
     * @var string
     */
    protected string $username = '';

    /**
     * @var string
     */
    protected string $password = '';

    /**
     * @var object
     */
    protected object $provider;

    /**
     * @var bool
     */
    protected bool $conditionSomeTables = false;

    /**
     * @var array
     */
    protected array $conditionTables = [];

    /**
     * @var bool
     */
    protected bool $includeSomeTableLimits = false;

    /**
     * @var array
     */
    protected array $limitTables = [];

    /**
     * @var string
     */
    protected string $contents = '';

    /**
     * @var bool
     */
    protected bool $includeSomeTables = false;

    /**
     * @var array
     */
    protected array $includeTables = [];

    /**
     * @var bool
     */
    protected bool $excludeSomeTables = false;

    /**
     * @var array
     */
    protected array $excludeTables = [];

    /**
     * @var array
     */
    protected array $tables = [];

    /**
     * Get the instance of the class
     *
     * @return static
     */
    public static function create()
    {
        return new self();
    }

    /**
     * @param string $host
     * @return $this
     */
    public function setHost(string $host)
    {
        $this->host = $host;

        return $this;
    }

    /**
     * @param int|null $port
     * @return $this
     */
    public function setPort(?int $port = null)
    {
        if ($port === 3306) {
            $port = null;
        }

        $this->port = $port;

        return $this;
    }

    /**
     * @param string|null $socket
     * @return $this
     */
    public function setSocket(?string $socket = null)
    {
        $this->socket = $socket;

        return $this;
    }

    /**
     * @param string $name
     * @return $this
     */
    public function setDatabase(string $name)
    {
        $this->database = $name;

        return $this;
    }

    public function setUsername(string $username)
    {
        $this->username = $username;

        return $this;
    }

    public function setPassword(string $password)
    {
        $this->password = $password;

        return $this;
    }

    /**
     * Get provider instance
     *
     * @return object
     */
    public function getProvider()
    {
        return $this->provider;
    }

    /**
     * @return Mysqldump
     * @throws \Exception
     */
    protected function dumper()
    {
        $host = !is_null($this->port) ? "{$this->host}:{$this->port}" : $this->host;

        return new Mysqldump(
            "mysql:host={$host};dbname={$this->database}",
            $this->username,
            $this->password,
            [
                'add-drop-table' => true,
                'skip-comments' => true,
                'default-character-set' => $this->charset,
                'include-tables' => $this->tables,
                'init_commands' => ['SET SESSION net_write_timeout = ' . self::NET_WRITE_TIMEOUT],
            ],
            $this->pdoSettings()
        );
    }

    /**
     * PDO attributes for the dump connection.
     *
     * The library defaults to persistent connections, which keep a slot occupied in the
     * PHP-FPM pool long after the dump finished. On hosts with a low `max_user_connections`
     * that is enough to lock the account out of its own database, so it is forced off here.
     *
     * @return array
     */
    protected function pdoSettings(): array
    {
        $settings = [
            \PDO::ATTR_PERSISTENT => false,
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_TIMEOUT => self::CONNECT_TIMEOUT,
        ];

        // Rows are streamed unbuffered so a whole table never lands in memory. The dump is
        // then only as fast as the disk it writes to, and a gap between two reads longer than
        // the server's net_write_timeout drops the connection mid-table ("2006 MySQL server
        // has gone away"), which loses the whole dump. The session raises that timeout to a
        // bounded value (NET_WRITE_TIMEOUT, via init_commands) instead of an open-ended one,
        // so a hung dump still cannot hold its connection slot for long.
        if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
            $settings[\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = false;
        }

        return $settings;
    }

    /**
     * Read the charset WordPress is actually connected with.
     *
     * Taken from the live connection rather than probed with one of our own, so listing the
     * schema costs no extra slot in the account's connection pool.
     *
     * @return $this
     */
    protected function resolveCharset()
    {
        global $wpdb;

        $charset = !empty($wpdb->charset)
            ? $wpdb->charset
            : Config::get('database.connections.wordpress.charset');

        if (!empty($charset)) {
            $this->charset = $charset;
        }

        return $this;
    }

    /**
     * Get the database tables
     *
     * Runs on the connection Laravel already holds. The dump itself still needs a dedicated
     * one (it streams unbuffered result sets inside a long transaction and rewrites session
     * variables), but enumerating tables does not.
     *
     * @return array
     */
    protected function getTargetTables()
    {
        $rows = DB::connection('wordpress')->select('SHOW TABLES');

        $tables = [];

        foreach ($rows as $row) {
            $values = array_values((array)$row);

            if (isset($values[0])) {
                $tables[] = $values[0];
            }
        }

        return $tables;
    }

    /**
     * Include only the tables mentioned in $tables
     *
     * @return $this
     * @throws \Exception
     * @var array $tables
     */
    public function includeTables(array $tables = [])
    {
        if (!empty($this->excludeSomeTables)) {
            throw new \Exception('Cannot set `includeTables` because it conflicts with parameter `excludeTables`.');
        }

        $this->includeSomeTables = true;
        $this->includeTables = $tables;

        return $this;
    }

    /**
     * Exclude only the tables mentioned in @param array $tables
     *
     * @return $this
     * @throws \Exception
     * @var $tables
     */
    public function excludeTables(array $tables = [])
    {
        if (!empty($this->includeSomeTables)) {
            throw new \Exception('Cannot set `excludeTables` because it conflicts with parameter `includeTables`.');
        }

        $this->excludeSomeTables = true;
        $this->excludeTables = $tables;

        return $this;
    }

    /**
     * Sets SQL like where clauses on tables before export
     *
     * @param array $tables
     *
     * @return $this
     */
    public function conditionTables(array $tables = [])
    {
        $this->conditionSomeTables = true;
        $this->conditionTables = $tables;

        return $this;
    }

    /**
     * Sets limits on tables before export
     *
     * @param array $tables
     *
     * @return $this
     */
    public function limitTables(array $tables = [])
    {
        $this->includeSomeTableLimits = true;
        $this->limitTables = $tables;

        return $this;
    }

    /**
     * @return void
     * @throws \Exception
     */
    protected function guardAgainstIncompleteCredentials()
    {
        foreach (['username', 'host', 'database'] as $requiredProperty) {
            if (\strlen($this->{$requiredProperty}) === 0) {
                throw new \Exception("Parameter `{$requiredProperty}` cannot be empty.");
            }
        }
    }


    /**
     * Guard against empty tables
     *
     * @return $this
     * @throws \Exception
     */
    protected function guardAgainstEmptyTables()
    {
        $tables = $this->getTargetTables();

        if ($this->includeSomeTables) {
            $tables = array_values(array_filter($this->includeTables, fn($table) => in_array($table, $tables)));
        } elseif ($this->excludeSomeTables) {
            $tables = array_values(array_filter($tables, fn($table) => !in_array($table, $this->excludeTables)));
        }

        $this->tables = $tables;

        if (empty($this->tables)) {
            throw new \Exception("No tables found on `{$this->database}");
        }

        return $this;
    }

    /**
     * Build the sql pre_insert_statements to export
     * @return $this
     * @throws \Exception
     */
    protected function prepareExportContentsFrom($path)
    {
        try {
            $this->provider = $this->dumper();

            if ($this->conditionSomeTables && !empty($this->conditionTables)) {
                $this->provider->setTableWheres($this->conditionTables);
            }

            if ($this->includeSomeTableLimits && !empty($this->limitTables)) {
                $this->provider->setTableLimits($this->limitTables);
            }

            $this->provider->start($path);
        } catch (\Exception $e) {
            if (file_exists($path)) {
                wp_delete_file($path);
            }

            throw $e;
        }

        return $this;
    }

    /**
     * This method allows you store the exported db to a directory
     *
     * @param $path
     *
     * @return $this
     * @throws \Exception
     */
    public function dumpToFile($path)
    {
        $this->guardAgainstIncompleteCredentials();

        $this->resolveCharset()
            ->guardAgainstEmptyTables();

        $this->prepareExportContentsFrom($path);

        return $this;
    }

    /**
     * Create dump
     *
     * @param string $path
     * @param string $host
     * @param int $port
     * @param string $database
     * @param string $username
     * @param string $password
     * @param array $excluded
     * @return void
     * @throws \Exception
     */
    public static function dump(string $path, array $connection, array $excluded)
    {
        $database = $connection['database'];
        $username = $connection['username'];
        $password = $connection['password'];

        $host = $connection['host'];
        $port = $connection['port'];
        $socket = $connection['socket'];

        static::create()
            ->setHost($host)
            ->setPort($port)
            ->setSocket($socket)
            ->setDatabase($database)
            ->setUsername($username)
            ->setPassword($password)
            ->excludeTables($excluded)
            ->dumpToFile($path);
    }
}
