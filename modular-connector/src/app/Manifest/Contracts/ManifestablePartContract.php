<?php

namespace Modular\Connector\Manifest\Contracts;

/**
 * Shared contract between domains that produce file manifests via the same
 * CalculateManifestJob pipeline (e.g. Backups\Iron\BackupPart and
 * Security\Imunify\ScanPart). A "part" represents a single disk slice with
 * its own manifest file path, status lifecycle and failure handling.
 */
interface ManifestablePartContract
{
    /**
     * @return bool
     */
    public function isCancelled();

    /**
     * @param string $status
     * @param \Throwable|null $e
     * @return void
     */
    public function markAsFailed($status, \Throwable $e = null);

    /**
     * @return ManifestContract
     */
    public function createManifest();

    /**
     * @return string
     */
    public function getManifestFailedStatus();
}
