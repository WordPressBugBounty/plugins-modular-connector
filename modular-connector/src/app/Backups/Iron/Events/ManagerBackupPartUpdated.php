<?php

namespace Modular\Connector\Backups\Iron\Events;

use Modular\Connector\Backups\Iron\BackupPart;
use Modular\Connector\Events\AbstractEvent;

class ManagerBackupPartUpdated extends AbstractEvent
{
    public const STATUS_EXCLUDED = 'excluded';
    public const STATUS_PENDING = 'pending';
    public const STATUS_MANIFEST_IN_PROGRESS = 'manifest_in_progress';
    public const STATUS_MANIFEST_UPLOAD_PENDING = 'manifest_upload_pending';
    public const STATUS_MANIFEST_UPLOADING = 'manifest_uploading';
    public const STATUS_MANIFEST_DONE = 'manifest_done';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_UPLOAD_PENDING = 'upload_pending';
    public const STATUS_UPLOADING = 'uploading';
    public const STATUS_DONE = 'done';

    public const STATUS_FAILED_FILE_NOT_FOUND = 'failed_file_not_found';
    public const STATUS_FAILED_UPLOADED = 'failed_uploaded';
    public const STATUS_FAILED_EXPORT_DATABASE = 'failed_export_database';
    public const STATUS_FAILED_EXPORT_FILES = 'failed_export_files';
    public const STATUS_FAILED_EXPORT_MANIFEST = 'failed_export_manifest';


    public function __construct(BackupPart $backupPart, string $status, array $extraArgs)
    {
        $offset = $backupPart->offset;
        $totalItems = $backupPart->totalItems;

        // If the status is manifest related, we don't want to update the offset
        if (in_array($status, [
            self::STATUS_MANIFEST_IN_PROGRESS,
            self::STATUS_MANIFEST_UPLOAD_PENDING,
            self::STATUS_MANIFEST_UPLOADING,
            self::STATUS_MANIFEST_DONE,
        ])) {
            $offset = 0;

            if (
                in_array($status, [
                    self::STATUS_MANIFEST_IN_PROGRESS,
                    self::STATUS_MANIFEST_UPLOAD_PENDING,
                    self::STATUS_MANIFEST_UPLOADING,
                ])
            ) {
                $totalItems = 0;
            }
        }

        $data = [
            'type' => $backupPart->type,
            'offset' => $offset,
            'total_items' => $totalItems,
            'batch' => $backupPart->batch,
            'batch_size' => $backupPart->batchSize,
            'site_backup' => $backupPart->siteBackup,
            'status' => $status,
        ];

        if (!empty($extraArgs)) {
            $data = array_merge($data, $extraArgs);
        }

        parent::__construct($backupPart->mrid, $data);
    }
}
