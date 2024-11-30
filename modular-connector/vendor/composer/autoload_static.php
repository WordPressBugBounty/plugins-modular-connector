<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInitd616516152e55af637d254ea028409d8
{
    public static $prefixLengthsPsr4 = array (
        'P' => 
        array (
            'Psr\\Container\\' => 14,
        ),
        'M' => 
        array (
            'Modular\\SDK\\' => 12,
            'Modular\\Connector\\' => 18,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'Psr\\Container\\' => 
        array (
            0 => __DIR__ . '/..' . '/psr/container/src',
        ),
        'Modular\\SDK\\' => 
        array (
            0 => __DIR__ . '/../..' . '/modular-php/src',
        ),
        'Modular\\Connector\\' => 
        array (
            0 => __DIR__ . '/../..' . '/src/app',
        ),
    );

    public static $classMap = array (
        'Composer\\InstalledVersions' => __DIR__ . '/..' . '/composer/InstalledVersions.php',
        'Modular\\Connector\\Console\\Kernel' => __DIR__ . '/../..' . '/src/app/Console/Kernel.php',
        'Modular\\Connector\\Events\\AbstractEvent' => __DIR__ . '/../..' . '/src/app/Events/AbstractEvent.php',
        'Modular\\Connector\\Events\\Backup\\AbstractBackupEvent' => __DIR__ . '/../..' . '/src/app/Events/Backup/AbstractBackupEvent.php',
        'Modular\\Connector\\Events\\Backup\\ManagerBackupFailedCreation' => __DIR__ . '/../..' . '/src/app/Events/Backup/ManagerBackupFailedCreation.php',
        'Modular\\Connector\\Events\\Backup\\ManagerBackupPartUpdated' => __DIR__ . '/../..' . '/src/app/Events/Backup/ManagerBackupPartUpdated.php',
        'Modular\\Connector\\Events\\Backup\\ManagerBackupPartsCalculated' => __DIR__ . '/../..' . '/src/app/Events/Backup/ManagerBackupPartsCalculated.php',
        'Modular\\Connector\\Events\\ManagerHealthUpdated' => __DIR__ . '/../..' . '/src/app/Events/ManagerHealthUpdated.php',
        'Modular\\Connector\\Events\\ManagerItemsActivated' => __DIR__ . '/../..' . '/src/app/Events/ManagerItemsActivated.php',
        'Modular\\Connector\\Events\\ManagerItemsDeactivated' => __DIR__ . '/../..' . '/src/app/Events/ManagerItemsDeactivated.php',
        'Modular\\Connector\\Events\\ManagerItemsDeleted' => __DIR__ . '/../..' . '/src/app/Events/ManagerItemsDeleted.php',
        'Modular\\Connector\\Events\\ManagerItemsInstalled' => __DIR__ . '/../..' . '/src/app/Events/ManagerItemsInstalled.php',
        'Modular\\Connector\\Events\\ManagerItemsUpdated' => __DIR__ . '/../..' . '/src/app/Events/ManagerItemsUpdated.php',
        'Modular\\Connector\\Events\\ManagerItemsUpgraded' => __DIR__ . '/../..' . '/src/app/Events/ManagerItemsUpgraded.php',
        'Modular\\Connector\\Exceptions\\HandleExceptions' => __DIR__ . '/../..' . '/src/app/Exceptions/HandleExceptions.php',
        'Modular\\Connector\\Exceptions\\Handler' => __DIR__ . '/../..' . '/src/app/Exceptions/Handler.php',
        'Modular\\Connector\\Facades\\Backup' => __DIR__ . '/../..' . '/src/app/Facades/Backup.php',
        'Modular\\Connector\\Facades\\Core' => __DIR__ . '/../..' . '/src/app/Facades/Core.php',
        'Modular\\Connector\\Facades\\Database' => __DIR__ . '/../..' . '/src/app/Facades/Database.php',
        'Modular\\Connector\\Facades\\Formatter' => __DIR__ . '/../..' . '/src/app/Facades/Formatter.php',
        'Modular\\Connector\\Facades\\Manager' => __DIR__ . '/../..' . '/src/app/Facades/Manager.php',
        'Modular\\Connector\\Facades\\Plugin' => __DIR__ . '/../..' . '/src/app/Facades/Plugin.php',
        'Modular\\Connector\\Facades\\Server' => __DIR__ . '/../..' . '/src/app/Facades/Server.php',
        'Modular\\Connector\\Facades\\Theme' => __DIR__ . '/../..' . '/src/app/Facades/Theme.php',
        'Modular\\Connector\\Facades\\Translation' => __DIR__ . '/../..' . '/src/app/Facades/Translation.php',
        'Modular\\Connector\\Facades\\WhiteLabel' => __DIR__ . '/../..' . '/src/app/Facades/WhiteLabel.php',
        'Modular\\Connector\\Facades\\WooCommerce' => __DIR__ . '/../..' . '/src/app/Facades/WooCommerce.php',
        'Modular\\Connector\\Helper\\OauthClient' => __DIR__ . '/../..' . '/src/app/Helper/OauthClient.php',
        'Modular\\Connector\\Http\\Controllers\\HandleController' => __DIR__ . '/../..' . '/src/app/Http/Controllers/HandleController.php',
        'Modular\\Connector\\Http\\Kernel' => __DIR__ . '/../..' . '/src/app/Http/Kernel.php',
        'Modular\\Connector\\Jobs\\AbstractJob' => __DIR__ . '/../..' . '/src/app/Jobs/AbstractJob.php',
        'Modular\\Connector\\Jobs\\Backup\\ManagerBackupCompressDatabaseJob' => __DIR__ . '/../..' . '/src/app/Jobs/Backup/ManagerBackupCompressDatabaseJob.php',
        'Modular\\Connector\\Jobs\\Backup\\ManagerBackupCompressFilesJob' => __DIR__ . '/../..' . '/src/app/Jobs/Backup/ManagerBackupCompressFilesJob.php',
        'Modular\\Connector\\Jobs\\Backup\\ManagerBackupUploadJob' => __DIR__ . '/../..' . '/src/app/Jobs/Backup/ManagerBackupUploadJob.php',
        'Modular\\Connector\\Jobs\\Health\\ManagerHealthDataJob' => __DIR__ . '/../..' . '/src/app/Jobs/Health/ManagerHealthDataJob.php',
        'Modular\\Connector\\Jobs\\Hooks\\HookSendEventJob' => __DIR__ . '/../..' . '/src/app/Jobs/Hooks/HookSendEventJob.php',
        'Modular\\Connector\\Jobs\\ManagerInstallJob' => __DIR__ . '/../..' . '/src/app/Jobs/ManagerInstallJob.php',
        'Modular\\Connector\\Jobs\\ManagerManageItemJob' => __DIR__ . '/../..' . '/src/app/Jobs/ManagerManageItemJob.php',
        'Modular\\Connector\\Jobs\\ManagerUpdateJob' => __DIR__ . '/../..' . '/src/app/Jobs/ManagerUpdateJob.php',
        'Modular\\Connector\\Jobs\\PendingDispatch' => __DIR__ . '/../..' . '/src/app/Jobs/PendingDispatch.php',
        'Modular\\Connector\\Listeners\\BackupRemoveEventListener' => __DIR__ . '/../..' . '/src/app/Listeners/BackupRemoveEventListener.php',
        'Modular\\Connector\\Listeners\\HookEventListener' => __DIR__ . '/../..' . '/src/app/Listeners/HookEventListener.php',
        'Modular\\Connector\\Listeners\\UpgradeTranslationsEventListener' => __DIR__ . '/../..' . '/src/app/Listeners/UpgradeTranslationsEventListener.php',
        'Modular\\Connector\\Providers\\EventServiceProvider' => __DIR__ . '/../..' . '/src/app/Providers/EventServiceProvider.php',
        'Modular\\Connector\\Providers\\ManagerServiceProvider' => __DIR__ . '/../..' . '/src/app/Providers/ManagerServiceProvider.php',
        'Modular\\Connector\\Queue\\Dispatcher' => __DIR__ . '/../..' . '/src/app/Queue/Dispatcher.php',
        'Modular\\Connector\\Queue\\Worker' => __DIR__ . '/../..' . '/src/app/Queue/Worker.php',
        'Modular\\Connector\\Services\\Backup\\BackupOptions' => __DIR__ . '/../..' . '/src/app/Services/Backup/BackupOptions.php',
        'Modular\\Connector\\Services\\Backup\\BackupPart' => __DIR__ . '/../..' . '/src/app/Services/Backup/BackupPart.php',
        'Modular\\Connector\\Services\\Backup\\BackupWorker' => __DIR__ . '/../..' . '/src/app/Services/Backup/BackupWorker.php',
        'Modular\\Connector\\Services\\Backup\\Dumper\\PHPDumper' => __DIR__ . '/../..' . '/src/app/Services/Backup/Dumper/PHPDumper.php',
        'Modular\\Connector\\Services\\Backup\\Dumper\\ShellDumper' => __DIR__ . '/../..' . '/src/app/Services/Backup/Dumper/ShellDumper.php',
        'Modular\\Connector\\Services\\Helpers\\Database' => __DIR__ . '/../..' . '/src/app/Services/Helpers/Database.php',
        'Modular\\Connector\\Services\\Helpers\\File' => __DIR__ . '/../..' . '/src/app/Services/Helpers/File.php',
        'Modular\\Connector\\Services\\Helpers\\Utils' => __DIR__ . '/../..' . '/src/app/Services/Helpers/Utils.php',
        'Modular\\Connector\\Services\\Manager' => __DIR__ . '/../..' . '/src/app/Services/Manager.php',
        'Modular\\Connector\\Services\\Manager\\AbstractManager' => __DIR__ . '/../..' . '/src/app/Services/Manager/AbstractManager.php',
        'Modular\\Connector\\Services\\Manager\\ManagerBackup' => __DIR__ . '/../..' . '/src/app/Services/Manager/ManagerBackup.php',
        'Modular\\Connector\\Services\\Manager\\ManagerContract' => __DIR__ . '/../..' . '/src/app/Services/Manager/ManagerContract.php',
        'Modular\\Connector\\Services\\Manager\\ManagerCore' => __DIR__ . '/../..' . '/src/app/Services/Manager/ManagerCore.php',
        'Modular\\Connector\\Services\\Manager\\ManagerDatabase' => __DIR__ . '/../..' . '/src/app/Services/Manager/ManagerDatabase.php',
        'Modular\\Connector\\Services\\Manager\\ManagerPlugin' => __DIR__ . '/../..' . '/src/app/Services/Manager/ManagerPlugin.php',
        'Modular\\Connector\\Services\\Manager\\ManagerServer' => __DIR__ . '/../..' . '/src/app/Services/Manager/ManagerServer.php',
        'Modular\\Connector\\Services\\Manager\\ManagerTheme' => __DIR__ . '/../..' . '/src/app/Services/Manager/ManagerTheme.php',
        'Modular\\Connector\\Services\\Manager\\ManagerTranslation' => __DIR__ . '/../..' . '/src/app/Services/Manager/ManagerTranslation.php',
        'Modular\\Connector\\Services\\Manager\\ManagerWhiteLabel' => __DIR__ . '/../..' . '/src/app/Services/Manager/ManagerWhiteLabel.php',
        'Modular\\Connector\\Services\\Manager\\ManagerWooCommerce' => __DIR__ . '/../..' . '/src/app/Services/Manager/ManagerWooCommerce.php',
        'Modular\\Connector\\WordPress\\Admin\\Menu\\Settings' => __DIR__ . '/../..' . '/src/app/WordPress/Admin/Menu/Settings.php',
        'Modular\\SDK\\ModularClient' => __DIR__ . '/../..' . '/modular-php/src/ModularClient.php',
        'Modular\\SDK\\ModularClientInterface' => __DIR__ . '/../..' . '/modular-php/src/ModularClientInterface.php',
        'Modular\\SDK\\Objects\\BaseObject' => __DIR__ . '/../..' . '/modular-php/src/Objects/BaseObject.php',
        'Modular\\SDK\\Objects\\BaseObjectFactory' => __DIR__ . '/../..' . '/modular-php/src/Objects/BaseObjectFactory.php',
        'Modular\\SDK\\Objects\\OauthToken' => __DIR__ . '/../..' . '/modular-php/src/Objects/OauthToken.php',
        'Modular\\SDK\\Objects\\SiteRequest' => __DIR__ . '/../..' . '/modular-php/src/Objects/SiteRequest.php',
        'Modular\\SDK\\Services\\AbstractService' => __DIR__ . '/../..' . '/modular-php/src/Services/AbstractService.php',
        'Modular\\SDK\\Services\\AbstractServiceFactory' => __DIR__ . '/../..' . '/modular-php/src/Services/AbstractServiceFactory.php',
        'Modular\\SDK\\Services\\BackupService' => __DIR__ . '/../..' . '/modular-php/src/Services/BackupService.php',
        'Modular\\SDK\\Services\\CoreServiceFactory' => __DIR__ . '/../..' . '/modular-php/src/Services/CoreServiceFactory.php',
        'Modular\\SDK\\Services\\OauthService' => __DIR__ . '/../..' . '/modular-php/src/Services/OauthService.php',
        'Modular\\SDK\\Services\\WordPressService' => __DIR__ . '/../..' . '/modular-php/src/Services/WordPressService.php',
        'Modular\\SDK\\Support\\ApiHelper' => __DIR__ . '/../..' . '/modular-php/src/Support/ApiHelper.php',
        'Psr\\Container\\ContainerExceptionInterface' => __DIR__ . '/..' . '/psr/container/src/ContainerExceptionInterface.php',
        'Psr\\Container\\ContainerInterface' => __DIR__ . '/..' . '/psr/container/src/ContainerInterface.php',
        'Psr\\Container\\NotFoundExceptionInterface' => __DIR__ . '/..' . '/psr/container/src/NotFoundExceptionInterface.php',
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInitd616516152e55af637d254ea028409d8::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInitd616516152e55af637d254ea028409d8::$prefixDirsPsr4;
            $loader->classMap = ComposerStaticInitd616516152e55af637d254ea028409d8::$classMap;

        }, null, ClassLoader::class);
    }
}
