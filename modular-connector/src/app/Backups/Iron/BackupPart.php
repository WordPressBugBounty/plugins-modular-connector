<?php

namespace Modular\Connector\Backups;

use Illuminate\Support\Facades\Storage;
use Modular\Connector\Backups\Phantom\Events\ManagerBackupPartUpdated;
use Modular\Connector\Helper\OauthClient;
use Modular\Connector\Jobs\Backup\ManagerBackupCompressFilesJob;
use Modular\Connector\Jobs\Backup\ManagerBackupUploadJob;
use Modular\Connector\Services\Helpers\File;

class BackupPart implements \JsonSerializable
{
    /**
     * @var string
     */
    public string $type;

    /**
     * @var BackupOptions
     */
    public BackupOptions $options;

    /**
     * @var int
     */
    public int $maxDepth = 0;

    /**
     * Cursor position (offset) of finder
     *
     * @var int
     */
    public int $offset = 0;

    /**
     * @var int Total items to include in this part
     */
    public int $totalItems = 0;

    /**
     * @var int Current batch number (ex. plugins-{$batch})
     */
    public int $batch = 1;

    /**
     * @var int Current batch size
     */
    public int $batchSize = 0;

    /**
     * @var string
     */
    public string $dbKey;

    /**
     * @var string Current status of this part
     */
    public string $status = self::PART_STATUS_EXCLUDED;

    public const PART_TYPE_DATABASE = 'database';
    public const PART_TYPE_CORE = 'core';
    public const PART_TYPE_PLUGINS = 'plugins';
    public const PART_TYPE_THEMES = 'themes';
    public const PART_TYPE_UPLOADS = 'uploads';

    public const PART_STATUS_EXCLUDED = 'excluded';

    public const PART_STATUS_PENDING = 'pending';
    public const PART_STATUS_IN_PROGRESS = 'in_progress';
    public const PART_STATUS_UPLOAD_PENDING = 'upload_pending';
    public const PART_STATUS_UPLOADING = 'uploading';
    public const PART_STATUS_DONE = 'done';

    public const STATUS_FAILED_FILE_NOT_FOUND = 'failed_file_not_found';
    public const STATUS_FAILED_EXPORT_DATABASE = 'failed_export_database';
    public const STATUS_FAILED_UPLOADED = 'failed_uploaded';
    public const STATUS_FAILED_EXPORT_FILES = 'failed_export_files';


    public function __construct(string $type, BackupOptions $options, int $maxDepth = 0)
    {
        $this->type = $type;
        $this->setOptions($options);
        $this->maxDepth = $maxDepth;
    }

    /**
     * @return Manifest
     */
    public function getManifestInstance(?int $maxDepth = null)
    {
        return Manifest::getInstance($this->options, $maxDepth ?: $this->maxDepth);
    }

    /**
     * @param BackupOptions $options
     * @return void
     */
    public function setOptions(BackupOptions $options)
    {
        $this->options = $options;
    }

    /**
     * @param string $key
     * @return void
     */
    public function setDbKey(string $key)
    {
        $this->dbKey = $key;
    }

    /**
     * @param array $extraArgs
     * @return void
     * @throws \Throwable
     */
    protected function update(array $extraArgs = [])
    {
        BackupWorker::getInstance()->update($this);
        ManagerBackupPartUpdated::dispatch($this, $extraArgs);
    }

    /**
     * @param string $status
     * @param array $extraArgs
     * @return void
     * @throws \Throwable
     */
    protected function markAs(string $status, array $extraArgs = [])
    {
        if ($this->status !== $status) {
            $this->status = $status;

            $this->update($extraArgs);
        }
    }

    /**
     * @return void
     */
    public function markAsPending()
    {
        $this->status = self::PART_STATUS_PENDING;

        if ($this->type !== self::PART_TYPE_DATABASE) {
            // If the part is not database, we need to add first folder to manifest
            $this->scanRootDir();
        }
    }

    /**
     * @return void
     * @throws \Throwable
     */
    public function markAsInProgress()
    {
        $this->markAs(self::PART_STATUS_IN_PROGRESS);
    }

    /**
     * @return void
     */
    public function markAsUploadPending()
    {
        $this->markAs(self::PART_STATUS_UPLOAD_PENDING);

        ManagerBackupUploadJob::dispatch($this);
    }

    /**
     * @return void
     */
    public function markAsUploading()
    {
        $this->markAs(self::PART_STATUS_UPLOADING);
    }

    /**
     * @return void
     * @throws \Throwable
     */
    public function markAsDone()
    {
        $this->markAs(self::PART_STATUS_DONE);

        BackupWorker::getInstance()->dispatch();
    }

    /**
     * @param string $status
     * @param \Throwable|null $e
     * @return void
     * @throws \Throwable
     */
    public function markAsFailed(string $status, ?\Throwable $e = null)
    {
        $error = [];

        if (!is_null($e)) {
            $error = [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ];
        }

        $this->markAs($status, [
            'error' => $error,
        ]);

        BackupWorker::getInstance()->dispatch();
    }

    /**
     * @return void
     */
    public function calculateMainManifest()
    {
        // We need to remove previous manifest
        $manifestPath = $this->getManifestInstance()->getPath($this->type, false);

        if (Storage::exists($manifestPath)) {
            Storage::delete($manifestPath);
        }

        $manifestIndexPath = $this->getManifestInstance()->getPath($this->type, false, 'index');

        if (Storage::exists($manifestIndexPath)) {
            Storage::delete($manifestIndexPath);
        }

        $manifestPath = $this->getManifestInstance()->getPath($this->type, true);

        if (Storage::exists($manifestPath)) {
            Storage::delete($manifestPath);
        }

        $manifestIndexPath = $this->getManifestInstance()->getPath($this->type, true, 'index');

        if (Storage::exists($manifestIndexPath)) {
            Storage::delete($manifestIndexPath);
        }

        // Get total items
        $this->totalItems = $this->getManifestInstance()
            ->make($this->type, true);

        if ($this->totalItems > 0) {
            $this->markAsPending();
        }
    }

    /**
     * @return array|false
     */
    public function getNextItemFromManifest()
    {
        $item = $this->getManifestInstance()->getItem($this);

        return !empty($item) ? $item : false;
    }

    public function scanRootDir()
    {
        $manifestInstance = $this->getManifestInstance();

        [$tree, $index] = $manifestInstance->scanDir($this, true);

        $manifestInstance->saveManifest($this->type, $tree, false);
        $manifestInstance->saveManifestIndex($this->type, [$index], false);
    }

    /**
     * @return void
     */
    public function scanDirs(array $dirs)
    {
        $carryDirs = [];
        $carryIndex = [];
        $manifestInstance = $this->getManifestInstance();

        array_walk(
            $dirs,
            function ($dir) use ($manifestInstance, &$carryDirs, &$carryIndex) {
                $items = $manifestInstance->scanDir($this, !($dir['depth'] >= $this->maxDepth), $dir);

                if (!$items) {
                    return;
                }

                [$tree, $index] = $items;

                $carryDirs = array_merge($carryDirs, $tree);
                $carryIndex[] = $index;
            }
        );

        $manifestInstance->saveManifest($this->type, $carryDirs, false);
        $manifestInstance->saveManifestIndex($this->type, $carryIndex, false);
    }

    /**
     * @return string
     */
    public function getZipName(): string
    {
        $suffix = $this->type;

        return sprintf('%s-%s-part-%d', $this->options->name, $suffix, $this->batch);
    }

    /**
     * Get real path of zip file
     *
     * @return string
     */
    public function getPath(bool $relative = false, string $extension = 'zip')
    {
        $name = $this->getZipName();
        $path = Backup::path(sprintf('%s/%s.%s', $this->options->name, $name, $extension));

        if (!$relative) {
            $path = Storage::path(ltrim($path, '/'));
        }

        return $path;
    }

    /**
     * @param \ZipArchive $zip
     * @param int $totalItems
     * @return bool
     * @throws \ErrorException
     * @throws \Throwable
     */
    public function checkIfBatchSizeIsOversize(\ZipArchive &$zip, int $totalItems): bool
    {
        if ($totalItems >= $this->options->limit) {
            // Close the zip file after added the files
            File::closeZip($zip);

            // Get real size of zip file
            $this->batchSize = $this->getZipSize();

            // If we have reached the limit, we need to stop
            return true;
        }

        if ($this->batchSize >= $this->options->batchMaxFileSize) {
            // Close the zip file after added the files
            File::closeZip($zip);

            // Get real size of zip file
            $this->batchSize = $this->getZipSize();

            if ($this->batchSize >= $this->options->batchMaxFileSize) {
                // Create new part before upload
                $newPart = clone $this;

                $newPart->batchSize = 0;
                $newPart->batch++;
                $newPart->offset++;
                $newPart->status = self::PART_STATUS_PENDING;

                BackupWorker::getInstance()->addPart($newPart);

                // Send current part to upload
                $this->markAsUploadPending();

                $zip = null;

                return true;
            }

            $zip = File::openZip($this->getPath());
        }

        return false;
    }

    /**
     * @return int
     */
    public function getZipSize()
    {
        $path = $this->getPath(true);

        return Storage::exists($path) ? Storage::size($path) : 0;
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

        return $client->backup->createUpload($this->options->siteBackup, [
            'name' => $this->getZipName(),
            'type' => $this->type,
            'batch' => $this->batch,
        ]);
    }

    /**
     * Check if the part is ready
     *
     * @param \ZipArchive|null $zip
     * @param $finished
     * @return void
     * @throws \ErrorException
     * @throws \Throwable
     */
    public function checkFilesIsReady(?\ZipArchive $zip, bool $finished)
    {
        // Close the zip file after added the files
        if ($zip instanceof \ZipArchive) {
            File::closeZip($zip);
        }

        $this->batchSize = $this->getZipSize();

        if ($finished) {
            $this->markAsUploadPending();
        } else {
            $this->update();

            ManagerBackupCompressFilesJob::dispatch($this);
        }
    }

    /**
     * Transform to array
     *
     * @return array
     */
    public function toArray(): array
    {
        return $this->jsonSerialize();
    }

    /**
     * Transform to array
     *
     * @return array
     */
    public function jsonSerialize(): array
    {
        return [
            'type' => $this->type,
            'offset' => $this->offset,
            'total_items' => $this->totalItems,
            'batch' => $this->batch,
            'batch_size' => $this->batchSize,
            'status' => $this->status,
            'site_backup' => $this->options->siteBackup,
            'options' => $this->options->toArray(),
        ];
    }
}
