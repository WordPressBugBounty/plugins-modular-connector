<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Database;

use Modular\ConnectorDependencies\Illuminate\Database\Connection;
use Modular\ConnectorDependencies\Illuminate\Database\DatabaseServiceProvider as IlluminateDatabaseServiceProvider;
class DatabaseServiceProvider extends IlluminateDatabaseServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        parent::register();
        if (class_exists('PDO')) {
            Connection::resolverFor('mysql', fn($connection, $database, $prefix, $config) => new MysqlConnection($connection, $database, $prefix, $config));
        }
    }
}
