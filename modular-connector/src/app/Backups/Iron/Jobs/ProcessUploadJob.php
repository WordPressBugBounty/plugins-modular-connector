<?php

namespace Modular\Connector\Backups\Iron\Jobs;

use Modular\Connector\Backups\Iron\BackupPart;
use Modular\Connector\Backups\Iron\Events\ManagerBackupPartUpdated;
use Modular\Connector\Helper\OauthClient;
use Modular\ConnectorDependencies\GuzzleHttp\Client;
use Modular\ConnectorDependencies\GuzzleHttp\Psr7\Utils;
use Modular\ConnectorDependencies\Illuminate\Bus\Queueable;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldQueue;
use Modular\ConnectorDependencies\Illuminate\Foundation\Bus\Dispatchable;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\File as FileFacade;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Storage;

class ProcessUploadJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    /**
     * @var BackupPart
     */
    protected BackupPart $part;

    /**
     * @var bool
     */
    protected bool $isManifest;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * @param BackupPart $part
     * @param bool $isManifest
     */
    public function __construct(BackupPart $part, bool $isManifest = false)
    {
        $this->part = $part;
        $this->isManifest = $isManifest;
        $this->queue = 'backups';
    }

    public function handle()
    {
        $part = $this->part;
        $isCancelled = $part->isCancelled();

        if ($isCancelled) {
            return;
        }

        $fileName = $this->isManifest ? $part->manifestPath : $part->getFileNameWithExtension('zip');

        if (!Storage::disk('backups')->exists($fileName)) {
            $part->markAs(ManagerBackupPartUpdated::STATUS_FAILED_FILE_NOT_FOUND);

            return;
        }

        $realPath = Storage::disk('backups')->path($fileName);

        $uploadingStatus = $this->isManifest ? ManagerBackupPartUpdated::STATUS_MANIFEST_UPLOADING : ManagerBackupPartUpdated::STATUS_UPLOADING;
        $part->markAs($uploadingStatus);

        try {
            $uploadUri = $this->getUploadUri();

            $resource = fopen($realPath, 'r');
            $stream = Utils::streamFor($resource);

            $guzzle = new Client();
            $guzzle->request('PUT', $uploadUri, ['body' => $stream]);

            $status = $this->isManifest ? ManagerBackupPartUpdated::STATUS_MANIFEST_DONE : ManagerBackupPartUpdated::STATUS_DONE;

            $part->markAs($status);
        } catch (\Throwable $e) {
            Log::error($e);

            $part->markAsFailed(ManagerBackupPartUpdated::STATUS_FAILED_UPLOADED, $e);
        }
    }

    /**
     * Get Upload URL
     *
     * @return mixed
     * @throws \ErrorException
     */
    public function getUploadUri(): string
    {
        $client = OauthClient::getClient();

        return $client->backup->createUpload($this->part->siteBackup, [
            'name' => FileFacade::basename($this->part->getFileName($this->isManifest)),
            'type' => $this->part->type,
            'batch' => $this->part->batch,
            'collection' => $this->isManifest ? 'manifest' : 'backups',
        ]);
    }

    /**
     * @return string
     */
    public function uniqueId(): string
    {
        return $this->part->mrid . '-' . $this->part->type . '-' . $this->part->batch;
    }
}
