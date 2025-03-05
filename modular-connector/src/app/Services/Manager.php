<?php

namespace Modular\Connector\Services;

use Modular\Connector\Facades\Backup;
use Modular\Connector\Facades\Server;
use Modular\Connector\Helper\OauthClient;
use Modular\Connector\Services\Manager\ManagerCore;
use Modular\Connector\Services\Manager\ManagerDatabase;
use Modular\Connector\Services\Manager\ManagerPlugin;
use Modular\Connector\Services\Manager\ManagerTheme;
use Modular\Connector\Services\Manager\ManagerTranslation;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\ServerSetup;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ClearableQueue;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Cache;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\File as FileFacade;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Storage;
use Modular\ConnectorDependencies\Illuminate\Support\Manager as IlluminateManager;
use Modular\ConnectorDependencies\Psr\Container\ContainerExceptionInterface;
use Modular\ConnectorDependencies\Psr\Container\NotFoundExceptionInterface;
use function Modular\ConnectorDependencies\app;

/**
 * This class receives the requests processed by the HandleController.php and delegates in to the specialized managers.
 */
class Manager extends IlluminateManager
{
    /**
     * Get the default driver name.
     *
     * @return string
     */
    public function getDefaultDriver()
    {
        return 'plugin';
    }

    /**
     * Returns a list with the existing plugins and themes.
     *
     * @return array
     */
    public function update()
    {
        ServerSetup::clean();

        $response = [
            'core' => $this->driver('core')->get(),
            'plugins' => $this->driver('plugin')->all(),
            'themes' => $this->driver('theme')->all(),
            'translations' => $this->driver('translation')->get(),
        ];

        Server::logout();

        return $response;
    }

    /**
     * Deletes all pending jobs from the queue.
     *
     * @param string $queueName
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function clearQueue(string $queueName = 'default')
    {
        $connection = app('config')->get('queue.default');

        $queue = app('queue')->connection($connection);

        if ($queue instanceof ClearableQueue) {
            $queue->clear($queueName);
        }
    }

    /**
     * Deletes all pending jobs and cleans all schedules, when plugin is deactivated.
     *
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function deactivate()
    {
        // 1. Clean schedules
        wp_unschedule_hook(app()->getScheduleHook());

        // 2. Clean all cache
        Cache::flush();

        // 3. Clean all pending jobs
        $this->clearQueue();
        $this->clearQueue('backups');

        try {
            Storage::disk('mu_plugins')->delete(MODULAR_CONNECTOR_MU_BASENAME);
        } catch (\Throwable $e) {
            // Silence is golden
        }
    }

    /**
     * Deletes the stored clients from DB and removes the `modular_backups` folder, when plugin is uninstalled.
     *
     * @return void
     */
    public static function uninstall()
    {
        OauthClient::uninstall();

        $path = Storage::disk('backups')->path('');
        FileFacade::deleteDirectory($path);
    }

    /**
     * @return ManagerPlugin
     */
    protected function createPluginDriver(): ManagerPlugin
    {
        return new ManagerPlugin();
    }

    /**
     * @return ManagerTheme
     */
    protected function createThemeDriver(): ManagerTheme
    {
        return new ManagerTheme();
    }

    /**
     * @return ManagerCore
     */
    protected function createCoreDriver(): ManagerCore
    {
        return new ManagerCore();
    }

    /**
     * @return ManagerTranslation
     */
    protected function createTranslationDriver(): ManagerTranslation
    {
        return new ManagerTranslation();
    }

    /**
     * @return ManagerDatabase
     */
    protected function createDatabaseDriver(): ManagerDatabase
    {
        return new ManagerDatabase();
    }
}
