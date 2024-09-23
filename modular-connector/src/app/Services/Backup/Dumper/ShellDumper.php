<?php

namespace Modular\Connector\Services\Backup\Dumper;

use Modular\Connector\Facades\Database;
use Modular\ConnectorDependencies\Spatie\DbDumper\Databases\MySql;

class ShellDumper
{
    public static function dump(string $path, array $connection, array $excluded)
    {
        $database = $connection['database'];
        $username = $connection['username'];
        $password = $connection['password'];

        $host = $connection['host'];
        $port = $connection['port'];
        $socket = $connection['socket'];

        $connection = MySql::create()
            ->setHost($host)
            ->setPort($port)
            ->setDbName($database)
            ->setUserName($username)
            ->excludeTables($excluded)
            ->setPassword($password);

        if (!empty($socket)) {
            $connection = $connection->setSocket($socket);
        }

        if (Database::engine() !== 'MariaDB') {
            $connection = $connection->doNotUseColumnStatistics();
        }

        // MariaDB don't use variable 'column-statistics=0' in the mysqldump,
        // so we need re-try without this variable
        $connection->dumpToFile($path);
    }
}
