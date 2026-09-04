<?php

namespace Modular\Connector\Backups\Iron\Manifest;

use Modular\Connector\Backups\Iron\Helpers\ManifestHasher;
use Modular\Connector\Manifest\AbstractManifest;

/**
 * Backup manifest: every file and directory of the disk, hashed with the
 * algorithm the API negotiated (`batch.hash_algo`, adaptive partial hashing
 * for large files). Everything else — enumeration, filtering, buffering,
 * re-dispatch, the final count — lives in `AbstractManifest`.
 */
class Manifest extends AbstractManifest
{
    /**
     * @return ManifestHasher
     */
    protected function createHasher(): ManifestHasher
    {
        return new ManifestHasher($this->part->hashAlgorithm);
    }
}
