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
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Application;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Bootloader;
use Modular\ConnectorDependencies\Illuminate\Contracts\Debug\ExceptionHandler as IlluminateExceptionHandler;
use Modular\ConnectorDependencies\Illuminate\Contracts\Http\Kernel as IlluminateHttpKernel;

require __DIR__ . '/../../vendor/scoper-autoload.php';

/**
 * @var Application $app
 */
$bootloader = Bootloader::getInstance();

$bootloader->boot(
    dirname(__DIR__),
    function (Application $app) {
        $app->singleton(IlluminateHttpKernel::class, HttpKernel::class);
        $app->singleton(IlluminateExceptionHandler::class, ExceptionHandler::class);

        try {
            $storagePath = MODULAR_CONNECTOR_STORAGE_PATH;

            if (!file_exists($storagePath)) {
                $created = @mkdir($storagePath, 0755, true);

                if (!$created) {
                    return; // If the storage path could not be created, we can't continue
                }
            }

            $subFolders = ['views', 'logs'];

            foreach ($subFolders as $subFolder) {
                $subPath = $storagePath . DIRECTORY_SEPARATOR . $subFolder;

                if (!file_exists($subPath)) {
                    $created = @mkdir($subPath, 0775, true);

                    if (!$created) {
                        @rmdir($storagePath . DIRECTORY_SEPARATOR . 'views'); // Remove the storage path
                        @rmdir($storagePath . DIRECTORY_SEPARATOR . 'logs'); // Remove the storage path
                        @rmdir($storagePath); // Remove the storage path

                        return;
                    }
                }

                // Create index.php, index.html and .htaccess files
                $indexPhp = $subPath . DIRECTORY_SEPARATOR . 'index.php';
                $indexHtml = $subPath . DIRECTORY_SEPARATOR . 'index.html';
                $htaccess = $subPath . DIRECTORY_SEPARATOR . '.htaccess';

                if (!file_exists($indexPhp)) {
                    @file_put_contents($indexPhp, '<?php // Silence is golden.');
                }

                if (!file_exists($indexHtml)) {
                    @file_put_contents($indexHtml, '');
                }

                if (!file_exists($htaccess)) {
                    @file_put_contents($htaccess, 'deny from all');
                }
            }

            // If the storage path was created, we can set it as the storage path for the application
            $app->useStoragePath($storagePath);
        } catch (\Throwable $e) {
            // Silence is golden
        }
    }
);
