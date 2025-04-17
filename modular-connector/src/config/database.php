<?php

global $wpdb;

if (!defined('DB_HOST')) {
    [$host, $port, $socket, $isIpv6] = ['localhost', 3306, null, false];
} else {
    [$host, $port, $socket, $isIpv6] = $wpdb->parse_db_host(DB_HOST);
}

if ($isIpv6 && extension_loaded('mysqlnd')) {
    $host = "[$host]";
}

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for all database work. Of course
    | you may use many connections at once using the Database library.
    |
    */

    'default' => 'wordpress',

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Here are each of the database connections setup for your application.
    | Of course, examples of configuring each database platform that is
    | supported by Laravel is shown below to make development simple.
    |
    |
    | All database work in Laravel is done through the PHP PDO facilities
    | so make sure you have the driver for your particular database of
    | choice installed on your machine before you begin development.
    |
    */

    'connections' => [
        'wordpress' => [
            'driver' => 'mysql',
            'host' => $host,
            'port' => $port ?: 3306,
            'database' => defined('DB_NAME') ? DB_NAME : null,
            'username' => defined('DB_USER') ? DB_USER : null,
            'password' => defined('DB_PASSWORD') ? DB_PASSWORD : null,
            'unix_socket' => $socket ?: null,
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => !empty($wpdb->prefix) ? $wpdb->prefix : '',
            'prefix_indexes' => true,
            'strict' => false,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? [
                \PDO::ATTR_PERSISTENT => false,
            ] : [],
        ],

        'modular' => [
            'driver' => 'mysql',
            'host' => $host,
            'port' => $port ?: 3306,
            'database' => defined('DB_NAME') ? DB_NAME : null,
            'username' => defined('DB_USER') ? DB_USER : null,
            'password' => defined('DB_PASSWORD') ? DB_PASSWORD : null,
            'unix_socket' => $socket ?: null,
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => (!empty($wpdb->prefix) ? $wpdb->prefix : '') . 'modular_',
            'prefix_indexes' => true,
            'strict' => false,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? [
                \PDO::ATTR_PERSISTENT => false,
            ] : [],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run in the database.
    |
    */

    'migrations' => 'migrations',

];
