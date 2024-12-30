<?php

if (!defined('ABSPATH')) {
    exit;
}

/*
|--------------------------------------------------------------------------
| Register The Composer Auto Loader
|--------------------------------------------------------------------------
|
| Composer provides a convenient, automatically generated class loader
| for our application. We just need to utilize it! We'll require it
| into the script here so we do not have to manually load any of
| our application's PHP classes. It just feels great to relax.
|
*/

use Modular\Connector\Exceptions\Handler as ExceptionHandler;
use Modular\Connector\Http\Kernel as HttpKernel;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Bootloader;
use Modular\ConnectorDependencies\Illuminate\Contracts\Debug\ExceptionHandler as IlluminateExceptionHandler;
use Modular\ConnectorDependencies\Illuminate\Contracts\Http\Kernel as IlluminateHttpKernel;

require __DIR__ . '/../../vendor/scoper-autoload.php';

/**
 * @var \Ares\Framework\Foundation\Application $app
 */
$bootloader = Bootloader::getInstance();

$bootloader->boot(
    dirname(__DIR__),
    function ($app) {
        $app->singleton(IlluminateHttpKernel::class, HttpKernel::class);
        $app->singleton(IlluminateExceptionHandler::class, ExceptionHandler::class);
    }
);
