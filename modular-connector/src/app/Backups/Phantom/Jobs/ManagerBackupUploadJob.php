<?php

namespace Modular\Connector\Backups\Phantom\Jobs;

use Modular\Connector\Backups\Phantom\BackupPart;
use Modular\ConnectorDependencies\GuzzleHttp\Client;
use Modular\ConnectorDependencies\GuzzleHttp\Psr7\Utils;
use Modular\ConnectorDependencies\Illuminate\Bus\Queueable;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldQueue;
use Modular\ConnectorDependencies\Illuminate\Foundation\Bus\Dispatchable;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Storage;

class ManagerBackupUploadJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    /**
     * @var BackupPart
     */
    protected BackupPart $part;

    /**
     * @param BackupPart $part
     */
    public function __construct(BackupPart $part)
    {
        $this->part = $part;
        $this->queue = 'backups';
    }

    public function handle()
    {
        $part = $this->part;

        $isCancelled = $part->options->isCancelled();

        if ($isCancelled) {
            return;
        }

        $part->markAsUploading();

        $name = $part->getZipName();
        $fileName = $name . '.zip';

        if (!Storage::disk('backups')->exists($fileName)) {
            $part->markAsFailed(BackupPart::STATUS_FAILED_FILE_NOT_FOUND);

            return;
        }

        $realPath = Storage::disk('backups')->path($fileName);

        try {
            $uploadUri = $part->getUploadUri();

            $resource = fopen($realPath, 'r');
            $stream = Utils::streamFor($resource);

            $guzzle = new Client();
            $guzzle->request('PUT', $uploadUri, ['body' => $stream]);

            $part->markAsDone();
        } catch (\Throwable $e) {
            Log::error($e);

            $part->markAsFailed(BackupPart::STATUS_FAILED_UPLOADED, $e);
        }
    }
}
