<?php

namespace Modular\Connector\Security\Imunify\Db;

use Modular\ConnectorDependencies\Illuminate\Support\Facades\DB;
use Modular\ConnectorDependencies\Illuminate\Support\Str;

/**
 * Discovers WordPress tables eligible for malware scanning.
 *
 * Primary path: auto-discovery via `information_schema.COLUMNS`. For every
 * table matching the configured prefix, build a descriptor from its primary
 * key plus every text/blob column. This broadens coverage beyond the MDS
 * default candidate set (posts/postmeta/options/comments/+plugin tables) to
 * include any plugin/custom table holding scannable content — `wp_usermeta`,
 * `wp_commentmeta`, WooCommerce order meta, Yoast indexable text, etc.
 *
 * Fallback: when `information_schema` is restricted (locked-down hosting) or
 * returns no usable rows, return the hardcoded `$candidates` list with its
 * known column targets and `base64` hints.
 *
 * Wire shape stays compatible with Imunify's `/scan/database/start`:
 *   {schema, name, key, columns, base64?}
 * Imunify's `scan_config` patterns are column-agnostic (generic LIKE patterns
 * for malware strings), so arbitrary tables work the same way at the data
 * plane — confirmed by Imunify's `/db-content` contract which only validates
 * per-row field presence, not table identity.
 */
class DbTablesDiscoverer
{
    /**
     * Fallback list — used only when auto-discovery returns nothing. Mirrors
     * aibolit's `mds_tables.config.json` wp_core section: the tables Imunify's
     * MDS engine ships rules for by default.
     *
     * @var array
     */
    public static $candidates = [
        'posts' => [
            'suffix' => 'posts',
            'key' => 'ID',
            'columns' => ['post_content'],
        ],
        'postmeta' => [
            'suffix' => 'postmeta',
            'key' => 'meta_id',
            'columns' => ['meta_value'],
            'base64' => true,
        ],
        'options' => [
            'suffix' => 'options',
            'key' => 'option_id',
            'columns' => ['option_value'],
            'base64' => true,
        ],
        'comments' => [
            'suffix' => 'comments',
            'key' => 'comment_ID',
            'columns' => ['comment_content', 'comment_author_email'],
        ],
        'hfcm_scripts' => [
            'suffix' => 'hfcm_scripts',
            'key' => 'script_id',
            'columns' => ['snippet'],
        ],
        'snippets' => [
            'suffix' => 'snippets',
            'key' => 'id',
            'columns' => ['code'],
        ],
    ];

    /**
     * Table suffixes whose content columns are known to carry serialized PHP
     * (often base64-wrapped to evade plain-text signature matching). Auto-
     * discovery sets `base64=true` on descriptors whose physical name ends in
     * one of these suffixes so `PrescanQueryBuilder` adds the
     * `base64_pattern` OR-clause to the LIKE filter.
     *
     * Adding a suffix here is non-breaking — it only enables an extra prescan
     * pattern; nothing about the wire contract or Imunify's processing changes.
     *
     * @var string[]
     */
    private static $base64Suffixes = ['postmeta', 'options', 'usermeta', 'commentmeta'];

    /**
     * Per-suffix exact-match denylist of columns that auto-discovery must
     * never include. WordPress-specific columns that don't match the generic
     * patterns below go here (e.g. `user_pass` doesn't contain `password`).
     *
     * @var array<string, string[]>
     */
    private static $sensitiveColumnsBySuffix = [
        'users' => ['user_pass', 'user_activation_key'],
    ];

    /**
     * Case-insensitive substring patterns for column names that should never
     * enter a scan descriptor, regardless of which table they live in.
     * Inspired by Sentry's default scrubbed fields and Datadog's process-args
     * scrubbing: credential-shaped column names are excluded on the assumption
     * that no plugin/theme stores malware payloads in fields whose name
     * advertises a secret.
     *
     * Substring matching is intentional — plugins use varied naming (`apikey`,
     * `api_key`, `stripe_api_key`, ...) and an exact-match list would miss
     * most of them.
     *
     * @var string[]
     */
    private static $sensitiveColumnNamePatterns = [
        'password', 'passwd', 'pwd',
        'secret',
        'token',
        'api_key', 'apikey',
        'private_key', 'privatekey',
        'credentials',
        'salt',
        'bearer',
        'credit_card', 'creditcard', 'card_number', 'cvv',
        'ssn',
    ];

    /**
     * Suffix → name of the "key" column for tables that store data as
     * `(key, value)` pairs. The prescan adds a `key_column NOT LIKE ?` clause
     * for each pattern in `$sensitiveRowKeyPatterns` so secrets stored as
     * values (e.g. `wp_options` rows like `*_api_key`, `wp_usermeta` rows
     * with `meta_key = 'session_tokens'`) never reach /db-content.
     *
     * @var array<string, string>
     */
    private static $keyValueTableConfig = [
        'options'     => 'option_name',
        'postmeta'    => 'meta_key',
        'usermeta'    => 'meta_key',
        'commentmeta' => 'meta_key',
        'termmeta'    => 'meta_key',
    ];

    /**
     * LIKE patterns (case-insensitive via WP's default `_ci` collation) applied
     * to the key column of key/value tables to skip rows whose key advertises
     * a credential or session secret. Bound as PDO values, never interpolated.
     *
     * Keep in sync with `$sensitiveColumnNamePatterns`: same vocabulary, wrapped
     * in `%` wildcards. `%token%` also covers WordPress core's `session_tokens`
     * usermeta row.
     *
     * @var string[]
     */
    private static $sensitiveRowKeyPatterns = [
        '%password%', '%passwd%', '%pwd%',
        '%secret%',
        '%token%',
        '%api_key%', '%apikey%',
        '%private_key%', '%privatekey%',
        '%credentials%',
        '%salt%',
        '%bearer%',
        '%credit_card%', '%creditcard%', '%card_number%', '%cvv%',
        '%ssn%',
    ];

    /**
     * MySQL column data types that can carry scannable text/blob content.
     * Numeric, datetime, binary-key, and enum/set columns are excluded —
     * malware signatures have nothing to match against in those types and
     * including them would inflate prescan I/O for no detection value.
     *
     * @var string[]
     */
    private static $scannableDataTypes = [
        'text', 'tinytext', 'mediumtext', 'longtext',
        'varchar', 'char',
        'blob', 'tinyblob', 'mediumblob', 'longblob',
        'json',
    ];

    /**
     * Return descriptors for tables eligible for malware scanning.
     *
     * Only tables matching the configured `$tablePrefix` exactly are returned
     * — the `information_schema` LIKE escapes `_` so other WordPress installs
     * sharing the same database (different prefix) are not picked up.
     *
     * Each descriptor: {schema, name, key, columns, base64?, row_filter?}.
     * `row_filter` is `{column, exclude_patterns}` and only present for
     * key/value tables; consumed by `PrescanQueryBuilder::executePrescan`.
     *
     * @param string $database
     * @param string $tablePrefix
     * @param array $excludedTables Physical table names to skip
     * @return array
     */
    public static function discover($database, $tablePrefix, array $excludedTables = [])
    {
        $excludedSet = array_flip(array_values($excludedTables));
        $autoDiscovered = self::autoDiscover($database, $tablePrefix, $excludedSet);

        if (!empty($autoDiscovered)) {
            return $autoDiscovered;
        }

        // information_schema unavailable or yielded no usable tables (no PK,
        // no text/blob columns, every table excluded). Fall back to the
        // hardcoded MDS-aligned list so the scan attempts something.
        return self::candidateDescriptors($database, $tablePrefix, $excludedSet);
    }

    /**
     * Auto-discover every prefixed table that has both a primary key and at
     * least one text/blob column.
     *
     * @param string $database
     * @param string $tablePrefix
     * @param array<string, mixed> $excludedSet
     * @return array
     */
    private static function autoDiscover($database, $tablePrefix, array $excludedSet)
    {
        $columnsByTable = self::loadSchemaColumns($database, $tablePrefix);
        if (empty($columnsByTable)) {
            return [];
        }

        $descriptors = [];
        foreach ($columnsByTable as $tableName => $columns) {
            if (isset($excludedSet[$tableName])) {
                continue;
            }

            $suffix = Str::after($tableName, $tablePrefix);
            $deniedColumns = isset(self::$sensitiveColumnsBySuffix[$suffix])
                ? self::$sensitiveColumnsBySuffix[$suffix]
                : [];

            $primaryKey = null;
            $scannableColumns = [];

            foreach ($columns as $column) {
                if ($primaryKey === null && $column['COLUMN_KEY'] === 'PRI') {
                    $primaryKey = $column['COLUMN_NAME'];
                }

                if (!in_array($column['DATA_TYPE'], self::$scannableDataTypes, true)) {
                    continue;
                }

                if (in_array($column['COLUMN_NAME'], $deniedColumns, true)) {
                    continue;
                }

                if (self::isSensitiveColumnName($column['COLUMN_NAME'])) {
                    continue;
                }

                $scannableColumns[] = $column['COLUMN_NAME'];
            }

            if ($primaryKey === null || empty($scannableColumns)) {
                // No PK → no cursor for cursor-paginated prescan. No text
                // columns → nothing to scan. Either way, skip.
                continue;
            }

            $descriptor = [
                'schema' => $database,
                'name' => $tableName,
                'key' => $primaryKey,
                'columns' => $scannableColumns,
            ];

            if (in_array($suffix, self::$base64Suffixes, true)) {
                $descriptor['base64'] = true;
            }

            $rowFilter = self::rowFilterFor($suffix);

            if ($rowFilter !== null) {
                $descriptor['row_filter'] = $rowFilter;
            }

            $descriptors[] = $descriptor;
        }

        return $descriptors;
    }

    /**
     * Case-insensitive substring check against `$sensitiveColumnNamePatterns`.
     * Returns true when the column name advertises a credential and should be
     * dropped from auto-discovery.
     *
     * @param string $columnName
     * @return bool
     */
    private static function isSensitiveColumnName($columnName)
    {
        return Str::contains(Str::lower($columnName), self::$sensitiveColumnNamePatterns);
    }

    /**
     * Build the `row_filter` descriptor field for a table suffix, or null when
     * the table is not a key/value store. Shared by both discovery paths so the
     * fallback gets the same row-level scrubbing as auto-discovery.
     *
     * @param string $suffix
     * @return array|null
     */
    private static function rowFilterFor($suffix)
    {
        if (!isset(self::$keyValueTableConfig[$suffix])) {
            return null;
        }

        return [
            'column' => self::$keyValueTableConfig[$suffix],
            'exclude_patterns' => self::$sensitiveRowKeyPatterns,
        ];
    }

    /**
     * Build descriptors directly from `$candidates` without verifying the
     * database. Returns the same shape `discover()` returns from the
     * auto-discovery path, so callers can consume either source uniformly.
     *
     * @param string $database
     * @param string $tablePrefix
     * @param array<string, mixed> $excludedSet array_flip()'d set of physical names to skip
     * @return array
     */
    private static function candidateDescriptors($database, $tablePrefix, array $excludedSet = [])
    {
        $descriptors = [];

        foreach (self::$candidates as $logicalName => $def) {
            $suffix = isset($def['suffix']) ? $def['suffix'] : $logicalName;
            $physicalName = $tablePrefix . $suffix;

            if (isset($excludedSet[$physicalName])) {
                continue;
            }

            $descriptor = [
                'schema' => $database,
                'name' => $physicalName,
                'key' => $def['key'],
                'columns' => $def['columns'],
            ];

            if (!empty($def['base64'])) {
                $descriptor['base64'] = true;
            }

            $rowFilter = self::rowFilterFor($suffix);

            if ($rowFilter !== null) {
                $descriptor['row_filter'] = $rowFilter;
            }

            $descriptors[] = $descriptor;
        }

        return $descriptors;
    }

    /**
     * Build `[tableName => [{COLUMN_NAME, COLUMN_KEY, DATA_TYPE}, ...]]` from
     * `information_schema.COLUMNS`. `_` is escaped in the LIKE so we don't
     * pick up tables from other WordPress installs sharing the database.
     *
     * @param string $database
     * @param string $tablePrefix
     * @return array<string, array<int, array{COLUMN_NAME: string, COLUMN_KEY: string, DATA_TYPE: string}>>
     */
    private static function loadSchemaColumns($database, $tablePrefix)
    {
        $escapedPrefix = Str::replace('_', '\\_', $tablePrefix);

        $rows = DB::select(
            'SELECT TABLE_NAME, COLUMN_NAME, COLUMN_KEY, DATA_TYPE FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = ? AND TABLE_NAME LIKE ?',
            [$database, $escapedPrefix . '%']
        );

        $map = [];
        foreach ($rows as $row) {
            $row = (array)$row;
            $table = $row['TABLE_NAME'];

            if (!isset($map[$table])) {
                $map[$table] = [];
            }

            $map[$table][] = [
                'COLUMN_NAME' => $row['COLUMN_NAME'],
                'COLUMN_KEY' => (string)$row['COLUMN_KEY'],
                'DATA_TYPE' => Str::lower((string)$row['DATA_TYPE']),
            ];
        }

        return $map;
    }
}
