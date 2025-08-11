<?php

namespace Modular\Connector\Backups\Iron;

use Modular\Connector\Backups\Contracts\BackupDriver;
use Modular\Connector\Backups\Facades\Backup;
use Modular\Connector\Backups\Facades\Backup as BackupFacade;
use Modular\Connector\Backups\Iron\Events\ManagerBackupPartsCalculated;
use Modular\Connector\Backups\Iron\Events\ManagerBackupPartUpdated;
use Modular\Connector\Backups\Iron\Jobs\DatabaseDumpJob;
use Modular\Connector\Backups\Iron\Manifest\CalculateManifestJob;
use Modular\Connector\Facades\Manager;
use Modular\Connector\Listeners\HookEventListener;
use Modular\ConnectorDependencies\Illuminate\Support\Collection;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Event;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\File as FileFacade;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Storage;
use function Modular\ConnectorDependencies\dispatch;
use function Modular\ConnectorDependencies\event;

class BackupIronDriver implements BackupDriver
{
    /**
     * @var
     */
    protected $payload;

    /**
     * @var
     */
    protected $requestId;

    public function listeners(): void
    {
        Event::listen(ManagerBackupPartUpdated::class, HookEventListener::class);
        Event::listen(ManagerBackupPartsCalculated::class, HookEventListener::class);
    }

    /**
     * @param $requestId
     * @param $payload
     * @return BackupIronDriver
     */
    public function options($requestId, $payload): self
    {
        $this->payload = $payload;
        $this->requestId = $requestId;

        return $this;
    }

    /**
     * @return void
     */
    public function make(): void
    {
        /**
         * @see config/filesystems.php
         */
        $types = Collection::make([
            BackupPart::INCLUDE_DATABASE,
            BackupPart::INCLUDE_CORE,
            BackupPart::INCLUDE_PLUGINS,
            BackupPart::INCLUDE_THEMES,
            BackupPart::INCLUDE_MU_PLUGINS,
            BackupPart::INCLUDE_CONTENT,
            BackupPart::INCLUDE_UPLOADS,
        ]);

        BackupFacade::init();

        $types = $types->map(
            fn($type) => (new BackupPart($this->requestId))
                ->setType($type)
                ->setPayload($this->payload)
                ->setManifestPath()
                ->calculateExclusion()
        );

        event(new ManagerBackupPartsCalculated($types));

        $types->where('status', ManagerBackupPartUpdated::STATUS_PENDING)
            ->each(function (BackupPart $part) {
                if ($part->type !== BackupPart::INCLUDE_DATABASE) {
                    // Ensure the manifest is empty before processing
                    Storage::disk('backups')->put($part->manifestPath, '');

                    dispatch(new CalculateManifestJob($part));
                } else {
                    dispatch(new DatabaseDumpJob($part));
                }
            });
    }

    /**
     * Deletes the backup with the provided $backupName from the backups folder if existing.
     *
     * @return void
     * @throws \Exception
     * @throws \Throwable
     */
    public function remove(?string $name, bool $removeAll = false)
    {
        $path = BackupFacade::path('*');

        $files = array_filter(
            FileFacade::glob($path),
            fn($file) => !in_array(FileFacade::basename($file), ['index.html', 'index.php', '.htaccess', 'web.config'])
        );

        // delete the previous
        if (!empty($files)) {
            FileFacade::delete($files);
        }

        if ($removeAll) {
            Backup::cancel($name);

            Manager::clearQueue('backups');
        }
    }
}
