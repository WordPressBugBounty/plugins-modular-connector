<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */
    'env' => defined('MODULAR_CONNECTOR_ENV') ? MODULAR_CONNECTOR_ENV : 'production',

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */
    'debug' => defined('MODULAR_CONNECTOR_DEBUG') && MODULAR_CONNECTOR_DEBUG,


    /*
    |--------------------------------------------------------------------------
    | Schedule HTTP Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */
    'debug_schedule' => defined('MODULAR_CONNECTOR_DEBUG_SCHEDULE') && MODULAR_CONNECTOR_DEBUG_SCHEDULE,

    /*
     * --------------------------------------------------------------------------
     * Loopback Requests
     * --------------------------------------------------------------------------
     *
     * This option determines whether the application should allow loopback
     * requests. Loopback requests are used by the application to
     * simulate HTTP requests to itself.
     */

    'loopback' => defined('MODULAR_CONNECTOR_LOOPBACK') ? MODULAR_CONNECTOR_LOOPBACK : true,

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. We have gone
    | ahead and set this to a sensible default for you out of the box.
    |
    */
    'timezone' => defined('MODULAR_CONNECTOR_TIMEZONE') ? MODULAR_CONNECTOR_TIMEZONE : 'UTC',

    /*
    |--------------------------------------------------------------------------
    | Autoloaded Service Providers
    |--------------------------------------------------------------------------
    |
    | The service providers listed here will be automatically loaded on the
    | request to your application. Feel free to add your own services to
    | this array to grant expanded functionality to your applications.
    |
    */
    'providers' => [
        Modular\ConnectorDependencies\Ares\Framework\Foundation\Auth\AuthServiceProvider::class,
        Modular\ConnectorDependencies\Ares\Framework\Foundation\Providers\BusServiceProvider::class,
        Modular\ConnectorDependencies\Ares\Framework\Foundation\Cache\CacheServiceProvider::class,
        Modular\ConnectorDependencies\Ares\Framework\Foundation\Database\DatabaseServiceProvider::class,
        Modular\ConnectorDependencies\Illuminate\Database\MigrationServiceProvider::class,
        Modular\ConnectorDependencies\Illuminate\Filesystem\FilesystemServiceProvider::class,
        Modular\ConnectorDependencies\Ares\Framework\Foundation\Providers\FoundationServiceProvider::class,
        Modular\ConnectorDependencies\Illuminate\View\ViewServiceProvider::class,
        Modular\ConnectorDependencies\Ares\Framework\Foundation\Queue\QueueServiceProvider::class,
        Modular\Connector\Providers\ModularConnectorServiceProvider::class,
        Modular\Connector\Providers\EventServiceProvider::class,
        Modular\Connector\Providers\RouteServiceProvider::class,
    ],
];
