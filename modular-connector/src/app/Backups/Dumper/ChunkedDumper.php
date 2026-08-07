<?php

namespace Modular\Connector\Backups\Dumper;

/**
 * Resumable dumper for a single large table, in primary-key chunks.
 *
 * Every SELECT covers one bounded chunk, so no query lives long enough for the server (or a
 * provider-side killer) to drop the connection mid-stream — the failure mode that makes the
 * monolithic dumpers lose whole multi-hundred-MB tables. Progress is a cursor the caller
 * persists between queue ticks; the segment file is truncated back to the last committed byte
 * on resume, so a tick killed mid-write never leaves half a chunk in the dump.
 *
 * Each segment is self-contained SQL (its own charset/FK guards, DROP + CREATE prologue), so
 * segments can be concatenated in any combination with shell-dumped ones.
 */
class ChunkedDumper
{
    /**
     * Seconds allowed to open the dump connection.
     */
    private const CONNECT_TIMEOUT = 10;

    /**
     * Server-side write timeout for the dump session. Same bounded trade-off as PHPDumper.
     */
    private const NET_WRITE_TIMEOUT = 300;

    /**
     * Target bytes per SELECT window; row count derives from the table's average row length.
     */
    private const CHUNK_TARGET_BYTES = 16 * 1024 * 1024;

    private const CHUNK_MIN_ROWS = 100;

    private const CHUNK_MAX_ROWS = 50000;

    /**
     * Flush an extended INSERT once it grows past this. Comfortably below any
     * max_allowed_packet a server would run with.
     */
    private const INSERT_MAX_BYTES = 1024 * 1024;

    /**
     * Integer types a single-column primary key must have to allow keyset pagination.
     */
    private const KEYSET_PK_TYPES = ['tinyint', 'smallint', 'mediumint', 'int', 'bigint'];

    private const NUMERIC_TYPES = [
        'tinyint', 'smallint', 'mediumint', 'int', 'bigint',
        'decimal', 'float', 'double',
    ];

    private const BINARY_TYPES = [
        'binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob', 'bit',
        'geometry', 'point', 'linestring', 'polygon',
        'multipoint', 'multilinestring', 'multipolygon', 'geometrycollection',
    ];

    /**
     * @var array
     */
    private array $connection;

    /**
     * @var string
     */
    private string $charset;

    /**
     * @var \PDO|null
     */
    private ?\PDO $pdo = null;

    public function __construct(array $connection, string $charset)
    {
        $this->connection = $connection;
        $this->charset = $charset;
    }

    /**
     * @param array $connection
     * @param string $charset
     * @return self
     */
    public static function create(array $connection, string $charset): self
    {
        return new self($connection, $charset);
    }

    /**
     * Dump one table into $path, resuming from $cursor. Returns true when the table is
     * complete, false when $shouldStop cut the tick short — the caller must persist the
     * updated cursor and call again.
     *
     * Cursor shape: ['pkColumn' => string|null, 'pk' => int|null, 'row' => int, 'bytes' => int]
     * Pass an empty array to start a table from scratch.
     *
     * @param string $path
     * @param string $table
     * @param array $cursor
     * @param int $avgRow average row length in bytes, used to size the SELECT window
     * @param callable $shouldStop checked between chunks; return true to stop the tick
     * @return bool
     * @throws \Throwable
     */
    public function dumpTable(string $path, string $table, array &$cursor, int $avgRow, callable $shouldStop): bool
    {
        if (!isset($cursor['bytes'])) {
            // Merge instead of replace: the caller keeps its own flags (e.g. the marker that
            // this table already fell back to chunking) alongside the dump state.
            $cursor = array_merge($cursor, [
                'pkColumn' => $this->resolveKeysetColumn($table),
                'pk' => null,
                'row' => 0,
                'bytes' => 0,
            ]);
        }

        $handle = fopen($path, 'c+b');

        if ($handle === false) {
            throw new \RuntimeException("Cannot open dump segment: {$path}");
        }

        try {
            // Anything past the last committed byte is a chunk a previous tick did not
            // finish writing: drop it and redo that chunk from the cursor.
            ftruncate($handle, $cursor['bytes']);
            fseek($handle, $cursor['bytes']);

            if ($cursor['bytes'] === 0) {
                fwrite($handle, $this->header($table));
                fflush($handle);
                $cursor['bytes'] = ftell($handle);
            }

            $columns = $this->columnTypes($table);
            $rowsPerChunk = (int)max(
                self::CHUNK_MIN_ROWS,
                min(self::CHUNK_MAX_ROWS, intdiv(self::CHUNK_TARGET_BYTES, max(1, $avgRow)))
            );

            while (true) {
                $written = $this->dumpChunk($handle, $table, $columns, $rowsPerChunk, $cursor);

                if ($written < $rowsPerChunk) {
                    fwrite($handle, $this->footer());
                    fflush($handle);
                    $cursor['bytes'] = ftell($handle);

                    return true;
                }

                if ($shouldStop()) {
                    return false;
                }
            }
        } finally {
            fclose($handle);
            $this->disconnect();
        }
    }

    /**
     * Write one SELECT window as extended INSERTs, committing the cursor only after the
     * whole chunk reached the file. Returns the number of rows written.
     *
     * @param resource $handle
     * @param string $table
     * @param array $columns
     * @param int $rowsPerChunk
     * @param array $cursor
     * @return int
     */
    private function dumpChunk($handle, string $table, array $columns, int $rowsPerChunk, array &$cursor): int
    {
        $quoted = $this->quoteIdentifier($table);

        if ($cursor['pkColumn'] !== null) {
            $pk = $this->quoteIdentifier($cursor['pkColumn']);

            if ($cursor['pk'] === null) {
                $sql = "SELECT * FROM {$quoted} ORDER BY {$pk} LIMIT {$rowsPerChunk}";
            } else {
                $sql = "SELECT * FROM {$quoted} WHERE {$pk} > " . (int)$cursor['pk'] . " ORDER BY {$pk} LIMIT {$rowsPerChunk}";
            }

            $pkIndex = $this->columnIndex($columns, $cursor['pkColumn']);
        } else {
            $offset = (int)$cursor['row'];
            $sql = "SELECT * FROM {$quoted} LIMIT {$rowsPerChunk} OFFSET {$offset}";
            $pkIndex = null;
        }

        $statement = $this->pdo()->query($sql, \PDO::FETCH_NUM);

        $count = 0;
        $buffer = '';
        $lastPk = $cursor['pk'];
        $insertPrefix = "INSERT INTO {$quoted} VALUES ";

        try {
            while (($row = $statement->fetch()) !== false) {
                $count++;

                if ($pkIndex !== null) {
                    $lastPk = (int)$row[$pkIndex];
                }

                $values = '(' . $this->escapeRow($row, $columns) . ')';

                $buffer .= ($buffer === '' ? $insertPrefix : ',') . $values;

                if (strlen($buffer) >= self::INSERT_MAX_BYTES) {
                    fwrite($handle, $buffer . ";\n");
                    $buffer = '';
                }
            }
        } finally {
            $statement->closeCursor();
        }

        if ($buffer !== '') {
            fwrite($handle, $buffer . ";\n");
        }

        fflush($handle);

        $cursor['pk'] = $lastPk;
        $cursor['row'] += $count;
        $cursor['bytes'] = ftell($handle);

        return $count;
    }

    /**
     * @param array $row
     * @param array $columns [['name' => ..., 'numeric' => bool, 'binary' => bool], ...]
     * @return string
     */
    private function escapeRow(array $row, array $columns): string
    {
        $escaped = [];

        foreach ($row as $index => $value) {
            if ($value === null) {
                $escaped[] = 'NULL';
            } elseif (!empty($columns[$index]['numeric']) && is_numeric($value)) {
                $escaped[] = (string)$value;
            } elseif (!empty($columns[$index]['binary'])) {
                $escaped[] = $value === '' ? "''" : '0x' . bin2hex($value);
            } else {
                $escaped[] = $this->pdo()->quote($value);
            }
        }

        return implode(',', $escaped);
    }

    /**
     * Self-contained prologue so concatenated segments never depend on each other.
     *
     * @param string $table
     * @return string
     */
    private function header(string $table): string
    {
        $quoted = $this->quoteIdentifier($table);

        $create = $this->pdo()
            ->query("SHOW CREATE TABLE {$quoted}")
            ->fetch(\PDO::FETCH_NUM);

        return "/*!40101 SET NAMES {$this->charset} */;\n"
            . "/*!40014 SET FOREIGN_KEY_CHECKS=0 */;\n"
            . "/*!40101 SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;\n"
            . "DROP TABLE IF EXISTS {$quoted};\n"
            . $create[1] . ";\n";
    }

    /**
     * @return string
     */
    private function footer(): string
    {
        return "/*!40014 SET FOREIGN_KEY_CHECKS=1 */;\n";
    }

    /**
     * Single-column integer primary key, or null when the table only supports OFFSET
     * pagination (composite or non-integer key, or no key at all).
     *
     * @param string $table
     * @return string|null
     */
    private function resolveKeysetColumn(string $table)
    {
        $statement = $this->pdo()->prepare(
            "SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_KEY = 'PRI'
             ORDER BY ORDINAL_POSITION"
        );
        $statement->execute([$table]);

        $primary = $statement->fetchAll(\PDO::FETCH_NUM);

        if (count($primary) !== 1) {
            return null;
        }

        return in_array(strtolower($primary[0][1]), self::KEYSET_PK_TYPES, true)
            ? $primary[0][0]
            : null;
    }

    /**
     * Column metadata in SELECT * order, driving per-value escaping.
     *
     * @param string $table
     * @return array
     */
    private function columnTypes(string $table): array
    {
        $statement = $this->pdo()->prepare(
            "SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
             ORDER BY ORDINAL_POSITION"
        );
        $statement->execute([$table]);

        $columns = [];

        foreach ($statement->fetchAll(\PDO::FETCH_NUM) as $column) {
            $type = strtolower($column[1]);

            $columns[] = [
                'name' => $column[0],
                'numeric' => in_array($type, self::NUMERIC_TYPES, true),
                'binary' => in_array($type, self::BINARY_TYPES, true),
            ];
        }

        return $columns;
    }

    /**
     * @param array $columns
     * @param string $name
     * @return int|null
     */
    private function columnIndex(array $columns, string $name)
    {
        foreach ($columns as $index => $column) {
            if ($column['name'] === $name) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param string $identifier
     * @return string
     */
    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    /**
     * Dedicated non-persistent connection, same budget rules as PHPDumper: opened lazily,
     * closed at the end of every tick so no slot is held between queue jobs.
     *
     * @return \PDO
     */
    private function pdo(): \PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $host = $this->connection['host'];
        $port = $this->connection['port'];
        $socket = $this->connection['socket'];

        if (!empty($socket)) {
            $dsn = "mysql:unix_socket={$socket};dbname={$this->connection['database']}";
        } else {
            $dsn = "mysql:host={$host}" . (!empty($port) && (int)$port !== 3306 ? ';port=' . (int)$port : '') . ";dbname={$this->connection['database']}";
        }

        // Buffered on purpose, unlike PHPDumper: a chunk is bounded (~16MB), so the server
        // hands the whole result over in seconds and the connection sits idle while the rows
        // drain to a slow disk — net_write_timeout never races the disk at all. Unbuffered
        // streaming is only worth its risk when the result set is a whole table.
        $this->pdo = new \PDO($dsn, $this->connection['username'], $this->connection['password'], [
            \PDO::ATTR_PERSISTENT => false,
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_TIMEOUT => self::CONNECT_TIMEOUT,
        ]);

        $this->pdo->exec('SET NAMES ' . $this->charset);
        $this->pdo->exec('SET SESSION net_write_timeout = ' . self::NET_WRITE_TIMEOUT);

        return $this->pdo;
    }

    /**
     * @return void
     */
    private function disconnect(): void
    {
        $this->pdo = null;
    }
}
