<?php

namespace Modular\ConnectorDependencies;

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
    'default' => \defined('Modular\ConnectorDependencies\MODULAR_CONNECTOR_QUEUE_SYNC') && \Modular\ConnectorDependencies\MODULAR_CONNECTOR_QUEUE_SYNC ? 'sync' : 'modular',
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
    'connections' => ['sync' => ['driver' => 'sync'], 'modular' => ['prefix' => 'modular', 'driver' => 'database', 'table' => 'options', 'queue' => 'default', 'retry_after' => 10 * 60]],
];
