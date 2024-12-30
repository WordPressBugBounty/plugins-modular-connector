<?php

namespace Modular\ConnectorDependencies;

use Modular\ConnectorDependencies\Illuminate\Http\Request;
use Modular\ConnectorDependencies\Illuminate\Support\Str;
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
    'env' => \defined('Modular\ConnectorDependencies\MODULAR_CONNECTOR_ENV') ? \Modular\ConnectorDependencies\MODULAR_CONNECTOR_ENV : 'production',
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
    'debug' => \defined('Modular\ConnectorDependencies\MODULAR_CONNECTOR_DEBUG') && \Modular\ConnectorDependencies\MODULAR_CONNECTOR_DEBUG,
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
    'debug_schedule' => \defined('Modular\ConnectorDependencies\MODULAR_CONNECTOR_DEBUG_SCHEDULE') && \Modular\ConnectorDependencies\MODULAR_CONNECTOR_DEBUG_SCHEDULE,
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
    'timezone' => \defined('Modular\ConnectorDependencies\MODULAR_CONNECTOR_TIMEZONE') ? \Modular\ConnectorDependencies\MODULAR_CONNECTOR_TIMEZONE : 'UTC',
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
    'providers' => [Ares\Framework\Foundation\Auth\AuthServiceProvider::class, \Modular\ConnectorDependencies\Ares\Framework\Foundation\Providers\BusServiceProvider::class, Ares\Framework\Foundation\Cache\CacheServiceProvider::class, Illuminate\Filesystem\FilesystemServiceProvider::class, Ares\Framework\Foundation\Providers\FoundationServiceProvider::class, Illuminate\View\ViewServiceProvider::class, Ares\Framework\Foundation\Queue\QueueServiceProvider::class, \Modular\Connector\Providers\ModularConnectorServiceProvider::class, \Modular\Connector\Providers\EventServiceProvider::class, \Modular\Connector\Providers\RouteServiceProvider::class],
    'router' => [
        'direct' => ['origin' => 'mo', 'type' => fn($value) => !empty($value)],
        // TODO Now we use Laravel routes but we can't directly use the routes
        //   because we need to check if the request is from our API
        //   so we disabled this for now
        // 'segments' => fn(Request $request) => $request->segment(1) === 'api' && $request->segment(2) === 'modular-connector',
        'segments' => fn(Request $request) => \false,
        'cron' => fn(Request $request) => \defined('DOING_CRON') && \DOING_CRON,
        'ajax' => fn(Request $request) => \defined('DOING_AJAX') && \DOING_AJAX && Str::startsWith($request->get('action', ''), 'modular_'),
    ],
];