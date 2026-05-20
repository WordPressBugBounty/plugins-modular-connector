<?php

namespace Modular\Connector\Security\Imunify\Db;

use Modular\ConnectorDependencies\Illuminate\Support\Facades\DB;

/**
 * Discovers WordPress tables eligible for malware scanning by validating each
 * candidate against information_schema. Mirrors the table layout used by Imunify's
 * MDS configuration: posts, postmeta, options, comments + optional plugin tables.
 */
class DbTablesDiscoverer
{
    /**
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
     * Return descriptors for tables that exist in the database with their required columns.
     *
     * Each descriptor: {schema, name, key, columns, base64?}
     *
     * @param string $database
     * @param string $tablePrefix
     * @param array $excludedTables Physical table names to skip
     * @param bool $onlyWithTheSamePrefix Reserved for future lookup that doesn't match by exact name
     * @return array
     */
    public static function discover($database, $tablePrefix, array $excludedTables = [], $onlyWithTheSamePrefix = false)
    {
        $schemaMap = self::loadSchemaMap($database, $tablePrefix);

        if (empty($schemaMap)) {
            return [];
        }

        $excludedSet = array_flip(array_values($excludedTables));
        $discovered = [];

        foreach (self::$candidates as $logicalName => $def) {
            $physicalName = $tablePrefix . (isset($def['suffix']) ? $def['suffix'] : $logicalName);

            if (!isset($schemaMap[$physicalName])) {
                continue;
            }

            if (isset($excludedSet[$physicalName])) {
                continue;
            }

            $tableColumns = $schemaMap[$physicalName];

            if (!isset($tableColumns[$def['key']])) {
                continue;
            }

            $allColumnsPresent = true;
            foreach ($def['columns'] as $col) {
                if (!isset($tableColumns[$col])) {
                    $allColumnsPresent = false;
                    break;
                }
            }

            if (!$allColumnsPresent) {
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

            $discovered[] = $descriptor;
        }

        return $discovered;
    }

    /**
     * Build a [tableName => [columnName => true]] map from information_schema.
     *
     * @param string $database
     * @param string $tablePrefix
     * @return array
     */
    private static function loadSchemaMap($database, $tablePrefix)
    {
        // Escape `_` so it stays literal in LIKE (avoids matching foreign tenants like wp1_users)
        $escapedPrefix = str_replace('_', '\\_', $tablePrefix);

        $rows = DB::select(
            'SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = ? AND TABLE_NAME LIKE ?',
            [$database, $escapedPrefix . '%']
        );

        $map = [];
        foreach ($rows as $row) {
            $row = (array)$row;
            $table = $row['TABLE_NAME'];
            $column = $row['COLUMN_NAME'];

            if (!isset($map[$table])) {
                $map[$table] = [];
            }

            $map[$table][$column] = true;
        }

        return $map;
    }
}
