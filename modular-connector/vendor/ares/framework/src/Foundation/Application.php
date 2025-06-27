<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation;

use Modular\ConnectorDependencies\Illuminate\Console\Application as ConsoleApplication;
use Modular\ConnectorDependencies\Illuminate\Filesystem\Filesystem;
use Modular\ConnectorDependencies\Illuminate\Foundation\Application as FoundationApplication;
use Modular\ConnectorDependencies\Illuminate\Foundation\PackageManifest as FoundationPackageManifest;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Cache;
class Application extends FoundationApplication
{
    /**
     * The Laravel framework version.
     *
     * @var string
     */
    public const VERSION = 'Ares 3.x (Laravel ' . parent::VERSION . ')';
    /**
     * Many sites have problems with the WP Cron system, so we need to force the schedule run.
     *
     * @var bool
     */
    public bool $forceDispatchScheduleRun = \false;
    /**
     * The array of terminating callbacks.
     *
     * @var callable[]
     */
    public array $afterTerminatingCallbacks = [];
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
     * Register a after terminating callback with the application.
     *
     * @param callable|string $callback
     * @return $this
     */
    public function afterTerminating($callback)
    {
        $this->afterTerminatingCallbacks[] = $callback;
        return $this;
    }
    /**
     * Terminate the application.
     *
     * @return void
     */
    public function terminate()
    {
        parent::terminate();
        $index = 0;
        while ($index < count($this->afterTerminatingCallbacks)) {
            $this->call($this->afterTerminatingCallbacks[$index]);
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
     * Determine if we need to force the schedule run.
     *
     * This method will be called when any job is dispatched to the queue.
     *
     * @param bool $force
     * @return $this
     */
    public function forceDispatchScheduleRun(bool $force = \true)
    {
        $this->forceDispatchScheduleRun = $force;
        Cache::driver('array')->forever('ares.forceDispatchScheduleRun', $force);
        return $this;
    }
    /**
     * Force to not dispatch the schedule run.
     *
     * @return $this
     */
    public function dontDispatchScheduleRun()
    {
        Cache::driver('array')->forever('ares.dontDispatchScheduleRun', \true);
        return $this;
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
    /**
     * Flush the container of all bindings and resolved instances.
     *
     * @return void
     */
    public function flush()
    {
        parent::flush();
        $this->afterTerminatingCallbacks = [];
    }
}
