<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation;

use Modular\ConnectorDependencies\Illuminate\Console\Application as ConsoleApplication;
use Modular\ConnectorDependencies\Illuminate\Filesystem\Filesystem;
use Modular\ConnectorDependencies\Illuminate\Foundation\Application as FoundationApplication;
use Modular\ConnectorDependencies\Illuminate\Foundation\PackageManifest as FoundationPackageManifest;
class Application extends FoundationApplication
{
    /**
     * The Laravel framework version.
     *
     * @var string
     */
    public const VERSION = 'Ares 3.x (Laravel ' . parent::VERSION . ')';
    /**
     * @return string
     */
    public function getScheduleHook()
    {
        return defined('MODULAR_ARES_SCHEDULE_HOOK') ? \MODULAR_ARES_SCHEDULE_HOOK : 'ares_schedule_run';
    }
    /**
     * Register the basic bindings into the container.
     *
     * @return void
     */
    protected function registerBaseBindings()
    {
        parent::registerBaseBindings();
        $this->singleton(FoundationPackageManifest::class, function () {
            return new PackageManifest(new Filesystem(), $this->basePath(), $this->getCachedPackagesPath());
        });
    }
    /**
     * Register the core class aliases in the container.
     *
     * @return void
     */
    public function registerCoreContainerAliases()
    {
        parent::registerCoreContainerAliases();
        $this->alias('app', self::class);
    }
    /**
     * Terminate the application.
     *
     * @return void
     */
    public function terminate()
    {
        $index = 0;
        while ($index < count($this->terminatingCallbacks)) {
            $this->call($this->terminatingCallbacks[$index]);
            $index++;
        }
    }
    /**
     * Determine if the application is currently down for maintenance.
     *
     * @return bool
     */
    public function isDownForMaintenance()
    {
        return is_file($this->storagePath() . '/framework/down') || wp_is_maintenance_mode();
    }
    /**
     * Format the given command as a fully-qualified executable command.
     *
     * @param string $string
     * @return string
     */
    public static function formatCommandString($string)
    {
        // FIXME: This is a temporary solution to avoid the error when the PHP binary is not found or not available.
        /*try {
              $binary = ConsoleApplication::phpBinary();
          } catch (\Throwable $e) {
              $binary = 'php';
          }*/
        return sprintf('%s %s %s', 'php', ConsoleApplication::artisanBinary(), $string);
    }
}
