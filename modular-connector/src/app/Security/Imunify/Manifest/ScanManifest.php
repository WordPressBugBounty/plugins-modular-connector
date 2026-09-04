<?php

namespace Modular\Connector\Security\Imunify\Manifest;

use Modular\Connector\Backups\Iron\Helpers\ManifestHasher;
use Modular\Connector\Manifest\AbstractManifest;
use Modular\Connector\Security\Imunify\Helpers\ConsolidatedManifest;
use Modular\ConnectorDependencies\Illuminate\Support\Collection;
use Modular\ConnectorDependencies\Illuminate\Support\Str;

/**
 * Scan manifest: only regular files with a scannable extension, hashed with
 * the full SHA256 Imunify matches verdicts against. Enumeration, filtering,
 * buffering, re-dispatch and the final count come from `AbstractManifest`.
 */
class ScanManifest extends AbstractManifest
{
    /**
     * Imunify resolves verdicts by the SHA256 of the whole file, so the
     * adaptive partial hashing backups use is not an option here.
     *
     * @return ManifestHasher
     */
    protected function createHasher(): ManifestHasher
    {
        return new ManifestHasher($this->part->hashAlgorithm, true);
    }

    /**
     * On top of the shared rule: directories never enter a scan manifest, only
     * allow-listed extensions do, and a symlinked FILE is only admitted when its
     * target lives inside one of our disk roots — the link's name decides the
     * extension, so a planted `debug.php -> /etc/passwd` would otherwise be
     * hashed and, on `needs_upload`, shipped off-box. Files reached through a
     * symlinked DIRECTORY are not links themselves and are admitted. Managed
     * hosts that link core files one by one into a shared mount (WP Engine)
     * lose those files from the scan — host-managed, read-only.
     *
     * @param \SplFileInfo $file
     * @param Collection $excluded
     * @param Collection $excludedExtensions
     * @return bool
     */
    protected function shouldSkip(\SplFileInfo $file, Collection $excluded, Collection $excludedExtensions): bool
    {
        if (!$file->isFile()) {
            return true;
        }

        if (parent::shouldSkip($file, $excluded, $excludedExtensions)) {
            return true;
        }

        if (!in_array(Str::lower($file->getExtension()), $this->part->allowedExtensions, true)) {
            return true;
        }

        if ($file->isLink() && !ConsolidatedManifest::isInsideDiskRoots($file->getRealPath(), true)) {
            return true;
        }

        return false;
    }

    /**
     * A file that cannot be hashed (empty, unreadable, vanished mid-scan) is
     * dropped: registering it with Imunify would leave a `needs_sha256` row we
     * can never satisfy and inflate `/scan/start`'s count against the hashes we
     * actually submit.
     *
     * @param \SplFileInfo $file
     * @param ManifestHasher $hasher
     * @return array|null
     */
    protected function mapItem(\SplFileInfo $file, ManifestHasher $hasher): ?array
    {
        $item = parent::mapItem($file, $hasher);

        if ($item === null || $item['checksum'] === null || $item['checksum'] === '') {
            return null;
        }

        return $item;
    }
}
