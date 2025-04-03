<?php

namespace Modular\Connector\Backups\Phantom;

use Modular\Connector\Backups\Contracts\BackupDriver;
use Modular\Connector\Backups\Facades\Backup;
use Modular\Connector\Backups\Facades\Backup as BackupFacade;
use Modular\Connector\Backups\Phantom\Events\ManagerBackupFailedCreation;
use Modular\Connector\Backups\Phantom\Events\ManagerBackupPartsCalculated;
use Modular\Connector\Backups\Phantom\Events\ManagerBackupPartUpdated;
use Modular\Connector\Backups\Phantom\Jobs\ManagerBackupCalculateManifestJob;
use Modular\Connector\Backups\Phantom\Listeners\BackupRemoveEventListener;
use Modular\Connector\Facades\Manager;
use Modular\Connector\Listeners\HookEventListener;
use Modular\ConnectorDependencies\Illuminate\Support\Collection;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Event;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\File as FileFacade;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use function Modular\ConnectorDependencies\dispatch;
use function Modular\ConnectorDependencies\event;

class BackupDriverPhantomDriver implements BackupDriver
{
    /**
     * @var BackupOptions|null
     */
    protected ?BackupOptions $options;

    /**
     * @return void
     */
    public function listeners(): void
    {
        Event::listen(ManagerBackupPartUpdated::class, BackupRemoveEventListener::class);
        Event::listen(ManagerBackupFailedCreation::class, BackupRemoveEventListener::class);

        Event::listen(ManagerBackupPartsCalculated::class, HookEventListener::class);
        Event::listen(ManagerBackupPartUpdated::class, HookEventListener::class);
        Event::listen(ManagerBackupFailedCreation::class, HookEventListener::class);
    }

    /**
     * @param $requestId
     * @param $payload
     * @return BackupDriverPhantomDriver
     */
    public function options($requestId, $payload): self
    {
        $this->options = new BackupOptions($requestId, $payload);

        return $this;
    }

    /**
     * @return void
     * @throws \Throwable
     */
    public function make(): void
    {
        try {
            BackupFacade::init();

            dispatch(new ManagerBackupCalculateManifestJob($this->options));
        } catch (\Throwable $e) {
            event(new ManagerBackupFailedCreation($this->options, $e));

            throw $e;
        }
    }

    /**
     * Deletes the backup with the provided $backupName from the backups folder if existing.
     *
     * @param string|null $name
     * @param bool $removeAll
     * @return void
     * @throws \Throwable
     */
    public function remove(?string $name, bool $removeAll = false)
    {
        try {
            $path = BackupFacade::path('*');

            $files = Collection::make(FileFacade::glob($path))
                ->filter(
                    fn($file) => FileFacade::isFile($file) &&
                        !in_array(FileFacade::basename($file), ['index.html', 'index.php', '.htaccess', 'web.config'])
                )->toArray();

            // delete the previous
            if (!empty($files)) {
                FileFacade::delete($files);
            }
        } catch (\Throwable $e) {
            // Silence is golden
            Log::error($e);
        }

        if ($removeAll) {
            Backup::cancel($name);

            BackupWorker::getInstance()->deleteAll();
            Manager::clearQueue('backups');
        }
    }
}
