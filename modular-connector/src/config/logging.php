<?php


return [

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | This option defines the default log channel that gets used when writing
    | messages to the logs. The name specified in this option should match
    | one of the channels defined in the "channels" configuration array.
    |
    */

    'default' => defined('MODULAR_LOG_CHANNEL') ? MODULAR_LOG_CHANNEL : 'daily',

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Here you may configure the log channels for your application. Out of
    | the box, Laravel uses the Monolog PHP logging library. This gives
    | you a variety of powerful log handlers / formatters to utilize.
    |
    | Available Drivers: "single", "daily", "slack", "syslog",
    |                    "errorlog", "monolog",
    |                    "custom", "stack"
    |
    */

    'channels' => [
        'single' => [
            'driver' => 'single',
            'path' => \Modular\ConnectorDependencies\storage_path('logs/modular-connector.log'),
            'level' => defined('MODULAR_CONNECTOR_LOG_LEVEL') ? MODULAR_CONNECTOR_LOG_LEVEL : 'error',
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => \Modular\ConnectorDependencies\storage_path('logs/modular-connector.log'),
            'level' => defined('MODULAR_CONNECTOR_LOG_LEVEL') ? MODULAR_CONNECTOR_LOG_LEVEL : 'error',
            'days' => 3,
        ],
    ],
];
