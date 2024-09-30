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
            ->setDbName($database)
            ->setUserName($username)
            ->excludeTables($excluded)
            ->setPassword($password);

        if (!empty($port) && is_int($port)) {
            $connection = $connection->setPort($port);
        }

        if (!empty($socket)) {
            $connection = $connection->setSocket($socket);
        }

        // MariaDB don't use variable 'column-statistics=0' in the mysqldump,
        if (Database::engine() !== 'MariaDB') {
            $connection = $connection->doNotUseColumnStatistics();
        }

        $connection->dumpToFile($path);
    }
}
