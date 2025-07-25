<?php

namespace Modular\Connector\Services;

use Modular\ConnectorDependencies\Illuminate\Support\Facades\DB;
use Modular\ConnectorDependencies\Illuminate\Support\Str;

class ServiceDatabase
{
    public const MYSQL_DB = 'MysqlDB';
    public const MARIA_DB = 'MariaDB';
    public const PERCONA_DB = 'Percona';

    public const ENGINE_MYISAM = 'MyISAM';
    public const ENGINE_ARCHIVE = 'ARCHIVE';
    public const ENGINE_ARIA = 'Aria';
    public const ENGINE_INNODB = 'InnoDB';

    public function showFullTables()
    {
        $tables = DB::select('SHOW FULL TABLES');

        $fullTables = [];

        foreach ($tables as $table) {
            $tableName = $table->{array_key_first((array)$table)};
            $tableType = $table->{array_key_last((array)$table)};
            $fullTables[$tableName] = $tableType;
        }

        return $fullTables;
    }

    public function showTableStatus($table = '')
    {
        $query = 'SHOW TABLE STATUS';

        if ($table) {
            $table = DB::getPdo()->quote($table);
            $query = "SHOW TABLE STATUS LIKE $table";
        }

        $tableStatus = DB::select($query);

        return count($tableStatus) === 1 ? $tableStatus[0] : $tableStatus;
    }

    public function getTableEngine($table)
    {
        $tableStatus = $this->showTableStatus($table);

        if (!$tableStatus) {
            return false;
        }

        if (!$tableStatus->Engine && $this->isView($table)) {
            return 'VIEW';
        }

        return $tableStatus->Engine;
    }

    public function isView($table)
    {
        $fullTables = $this->showFullTables();

        if (!array_key_exists($table, $fullTables)) {
            return false;
        }

        return ('VIEW' === $fullTables[$table]);
    }

    public function isOptimizable($table)
    {
        $serverType = $this->getServerType();
        $serverVersion = $this->getServerVersion();
        $engine = $this->getTableEngine($table);

        $validEngines = [self::ENGINE_MYISAM, self::ENGINE_ARCHIVE, self::ENGINE_ARIA];

        if (in_array($engine, $validEngines)) {
            return true;
        }

        if (self::ENGINE_INNODB === $engine) {
            if (self::MYSQL_DB === $serverType && $this->supportsDDL()) {
                return true;
            }

            if (self::MARIA_DB == $serverType) {
                if ($this->isVariableEnabled('innodb_file_per_table') || (version_compare($serverVersion, '10.1.1', '>=') && $this->isVariableEnabled('innodb_defragment'))) {
                    return true;
                }
            }
        }

        return false;
    }

    public function supportsTableTypeOptimization($table)
    {
        $tableType = $this->getTableEngine($table);

        $supportedTableTypes = [
            self::ENGINE_MYISAM,
            self::ENGINE_INNODB,
            self::ENGINE_ARCHIVE,
            self::ENGINE_ARIA,
        ];

        return in_array($tableType, $supportedTableTypes);
    }

    public function getServerType()
    {
        $serverType = self::MYSQL_DB;

        $variables = DB::select('SHOW SESSION VARIABLES LIKE "version%"');

        if (empty($variables)) {
            return $serverType;
        }

        foreach ($variables as $variable) {
            if (preg_match('/mariadb/i', $variable->Value)) {
                $serverType = self::MARIA_DB;
            }
            if (preg_match('/percona/i', $variable->Value)) {
                $serverType = self::PERCONA_DB;
            }
        }

        return $serverType;
    }

    public function getServerVersion()
    {
        $version = $this->getVariable('version');

        if (!$version) {
            return false;
        }

        if (preg_match('/^(\d+)(\.\d+)+/', $version, $match)) {
            return $match[0];
        }

        return false;
    }

    public function getVariable($variableName, $default = null)
    {
        $variableName = DB::getPdo()->quote($variableName);
        $option = DB::selectOne("SHOW SESSION VARIABLES LIKE $variableName");

        return empty($option) ? $default : $option->Value;
    }

    public function isVariableEnabled($variableName)
    {
        $optionValue = $this->getVariable($variableName);

        return Str::upper($optionValue) == 'ON';
    }

    public function supportsDDL()
    {
        if (self::MYSQL_DB == $this->getServerType()) {
            if (version_compare($this->getServerVersion(), '5.7', '>=')) {
                return true;
            } else {
                return false;
            }
        } elseif (self::MARIA_DB == $this->getServerType()) {
            if (version_compare($this->getServerVersion(), '10.0.0', '>=')) {
                return true;
            } else {
                return false;
            }
        }

        return false;
    }
}
