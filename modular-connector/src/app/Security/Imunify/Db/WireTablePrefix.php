<?php

namespace Modular\Connector\Security\Imunify\Db;

use Modular\ConnectorDependencies\Illuminate\Support\Str;

/**
 * Table names as Imunify sees them.
 *
 * WordPress accepts an empty `$table_prefix`, but Imunify answers
 * `400 Invalid request body` to `/scan/database/start` with `db_prefix: ""`.
 * For such a site the prefix is virtualised on the wire: `db_prefix` becomes
 * PLACEHOLDER and every table name travels as PLACEHOLDER + name, so Imunify
 * sees a regular prefixed install. Names coming back are stripped again. Sites
 * with a real prefix go through untouched, and local SQL always uses the real
 * names.
 */
class WireTablePrefix
{
    public const PLACEHOLDER = 'nopfx_';

    /**
     * @param string $prefix Real WordPress table prefix.
     * @return string
     */
    public static function prefix(string $prefix): string
    {
        return $prefix === '' ? self::PLACEHOLDER : $prefix;
    }

    /**
     * @param string $table Real table name.
     * @param string $prefix Real WordPress table prefix.
     * @return string
     */
    public static function toWire(string $table, string $prefix): string
    {
        return $prefix === '' ? self::PLACEHOLDER . $table : $table;
    }

    /**
     * @param string $table Table name as Imunify returned it.
     * @param string $prefix Real WordPress table prefix.
     * @return string
     */
    public static function fromWire(string $table, string $prefix): string
    {
        return $prefix === '' && Str::startsWith($table, self::PLACEHOLDER)
            ? Str::after($table, self::PLACEHOLDER)
            : $table;
    }
}
