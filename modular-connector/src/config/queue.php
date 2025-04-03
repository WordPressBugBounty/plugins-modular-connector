<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Laravel's queue API supports an assortment of back-ends via a single
    | API, giving you convenient access to each back-end using the same
    | syntax for every one. Here you may define a default connection.
    |
    */

    'default' => defined('MODULAR_CONNECTOR_QUEUE_DRIVER') ? MODULAR_CONNECTOR_QUEUE_DRIVER : 'wordpress',

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection information for each server that
    | is used by your application. A default configuration has been added
    | for each back-end shipped with Laravel. You are free to add more.
    |
    | Drivers: "sync", "database", "beanstalkd", "sqs", "redis", "null"
    |
    */

    'connections' => [
        'sync' => [
            'driver' => 'sync',
        ],

        'wordpress' => [
            'prefix' => 'modular',
            'driver' => 'wordpress',
            'table' => 'options',
            'queue' => 'default',
            'retry_after' => 10 * 60, // 10 minutes
        ],

        'database' => [
            'driver' => 'database',
            'connection' => 'modular',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 10 * 60, // 10 minutes
        ],
    ],

    'failed' => [
        'driver' => null,
    ],
];
