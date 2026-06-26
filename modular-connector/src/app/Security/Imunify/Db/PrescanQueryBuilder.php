<?php

namespace Modular\Connector\Security\Imunify\Db;

use Modular\ConnectorDependencies\Illuminate\Support\Facades\DB;
use Modular\ConnectorDependencies\Illuminate\Support\Str;

/**
 * Builds and executes the local prescan SQL queries that filter database rows
 * before submitting suspicious content to Imunify.
 *
 * Mirrors the reference plugin's `PrescanQueryBuilder`:
 *   - Reads `prescan_patterns`, `base64_pattern`, `size_limit` from scan_config.
 *   - Validates every pattern with a strict allow-list regex before embedding it
 *     into the LIKE clause (defense in depth — even though PDO binds the value,
 *     `base64_pattern` is interpolated textually so it must be sanitized).
 *   - Uses cursor pagination (`WHERE key > :lastKey ORDER BY key LIMIT N`) to
 *     scale to large tables without quadratic OFFSET cost.
 *   - Skips rows whose column LENGTH exceeds `size_limit` so HEX-encoded payload
 *     stays bounded.
 *   - For columns flagged base64, appends `base64_pattern` (with `col` placeholder
 *     replaced by the quoted column name) as an additional OR condition — NOT as
 *     a substitute for the regular LIKE patterns.
 */
class PrescanQueryBuilder
{
    /**
     * Default Imunify size_limit when scan_config does not override it.
     * Matches the reference plugin's fallback (~665 KB).
     */
    private const DEFAULT_SIZE_LIMIT = 665600;

    /**
     * @var array
     */
    private $prescanPatterns;

    /**
     * @var string
     */
    private $base64Pattern;

    /**
     * @var int
     */
    private $sizeLimit;

    /**
     * @var int
     */
    private $batchSize;

    /**
     * @param array $scanConfig Response from POST /scan/database/start (the scan_config field)
     * @param int $batchSize Rows per batch (default 500)
     */
    public function __construct(array $scanConfig, $batchSize = 500)
    {
        $rawPatterns = isset($scanConfig['prescan_patterns']) && is_array($scanConfig['prescan_patterns'])
            ? $scanConfig['prescan_patterns']
            : [];

        $this->prescanPatterns = array_values(array_filter($rawPatterns, [$this, 'isSafePrescanPattern']));

        $base64 = isset($scanConfig['base64_pattern']) ? (string)$scanConfig['base64_pattern'] : '';
        $this->base64Pattern = $this->isSafeBase64Pattern($base64) ? $base64 : '';

        $this->sizeLimit = isset($scanConfig['size_limit']) && is_int($scanConfig['size_limit'])
            ? max(1, $scanConfig['size_limit'])
            : self::DEFAULT_SIZE_LIMIT;

        $this->batchSize = max(1, (int)$batchSize);
    }

    /**
     * @return array
     */
    public function getPrescanPatterns()
    {
        return $this->prescanPatterns;
    }

    /**
     * Execute the prescan for a single table/column combination.
     * Yields one batch (array of rows) at a time so callers can submit + check
     * timeout between batches.
     *
     * Each yielded row: ['table' => ..., 'column' => ..., 'row_id' => ..., 'content' => HEX_STRING]
     *
     * @param string $table
     * @param string $column
     * @param string $key
     * @param bool $isBase64
     * @param string|int $lastKey Cursor: yield rows with key strictly greater than this.
     *                            Pass '' or 0 to start from the beginning.
     * @return \Generator yields array of rows
     */
    public function executePrescan($table, $column, $key, $isBase64, $lastKey = 0)
    {
        if (empty($this->prescanPatterns)) {
            return;
        }

        $tableId = $this->escapeIdentifier($table);
        $keyId = $this->escapeIdentifier($key);
        $columnId = $this->escapeIdentifier($column);

        $quotedColumn = sprintf('`%s`', $columnId);
        $quotedKey = sprintf('`%s`', $keyId);
        $quotedTable = sprintf('`%s`', $tableId);

        $conditions = [];
        $bindings = [];
        foreach ($this->prescanPatterns as $pattern) {
            $conditions[] = $quotedColumn . ' LIKE ?';
            $bindings[] = $pattern;
        }

        if ($isBase64 && $this->base64Pattern !== '') {
            // base64_pattern is interpolated textually (already sanitized in __construct).
            $conditions[] = '(' . Str::replace('col', $quotedColumn, $this->base64Pattern) . ')';
        }

        $whereSql = implode(' OR ', $conditions);

        $sql = sprintf(
            'SELECT %s AS row_key, HEX(%s) AS hex_content '
            . 'FROM %s '
            . 'WHERE %s > ? AND LENGTH(%s) <= %d AND (%s) '
            . 'ORDER BY %s LIMIT %d',
            $quotedKey,
            $quotedColumn,
            $quotedTable,
            $quotedKey,
            $quotedColumn,
            $this->sizeLimit,
            $whereSql,
            $quotedKey,
            $this->batchSize
        );

        while (true) {
            $rows = DB::select($sql, array_merge([$lastKey], $bindings));

            if (empty($rows)) {
                return;
            }

            $batch = [];
            foreach ($rows as $row) {
                $row = (array)$row;
                $batch[] = [
                    'table' => $table,
                    'column' => $column,
                    'row_id' => (string)$row['row_key'],
                    'content' => $row['hex_content'],
                ];
                $lastKey = $row['row_key'];
            }

            yield $batch;

            if (count($rows) < $this->batchSize) {
                return;
            }
        }
    }

    /**
     * Reject identifiers that contain anything beyond word chars to prevent SQL injection
     * since identifiers cannot be parameterized.
     *
     * @param string $identifier
     * @return string
     */
    private function escapeIdentifier($identifier)
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $identifier)) {
            throw new \RuntimeException(sprintf('Unsafe SQL identifier rejected: %s', Str::substr($identifier, 0, 50)));
        }

        return Str::replace('`', '``', $identifier);
    }

    /**
     * Allow-list for prescan patterns received from Imunify. Reject anything with
     * SQL-dangerous characters (semicolons, comment sequences are caught by the
     * regex below since `;` and `-` outside of `\\\-` are not in the class).
     *
     * @param mixed $pattern
     * @return bool
     */
    private function isSafePrescanPattern($pattern)
    {
        if (!is_string($pattern) || $pattern === '') {
            return false;
        }

        return preg_match('/^[a-zA-Z0-9%_<>?!@#&()=:\/\\\\.^*+\-\s]+$/', $pattern) === 1;
    }

    /**
     * Allow-list for the base64 expression pattern. More permissive than the
     * prescan one (it is a SQL expression, not just a LIKE value) but still
     * rejects semicolons and comment markers.
     *
     * @param string $pattern
     * @return bool
     */
    private function isSafeBase64Pattern($pattern)
    {
        if (!is_string($pattern) || $pattern === '') {
            return false;
        }

        if (preg_match('/[;]|--|\/\*/', $pattern)) {
            return false;
        }

        return preg_match('/^[a-zA-Z0-9%_\'()=<>:\/\\\\.^*+\-\s,\[\]{}|?$]+$/', $pattern) === 1;
    }
}
