<?php

namespace Modular\Connector\BrokenLinks\Ndjson\Manifest;

use Modular\Connector\BrokenLinks\Ndjson\BrokenLinkPart;
use Modular\Connector\Manifest\Contracts\ManifestContract;

/**
 * Bridge between BrokenLinkPart and ManifestContract.
 *
 * BLC does not use the shared CalculateManifestJob pipeline — discovery is
 * interleaved with NDJSON writes inside ProcessBrokenLinkBatchJob. This class
 * exists purely so BrokenLinkPart can implement ManifestablePartContract
 * (structural parity with BackupPart / ScanPart), and exposes the running
 * link count for the orchestration layer.
 */
class BrokenLinkManifest implements ManifestContract
{
    /** @var BrokenLinkPart */
    private $part;

    public function __construct(BrokenLinkPart $part)
    {
        $this->part = $part;
    }

    public static function create(BrokenLinkPart $part): self
    {
        return new self($part);
    }

    /**
     * No-op: the manifest is produced lazily by ProcessBrokenLinkBatchJob as
     * posts are rendered and links extracted. The shared CalculateManifestJob
     * never dispatches against a BrokenLinkPart.
     *
     * @return void
     */
    public function calculate()
    {
        // Intentional no-op — see class docblock.
    }

    /**
     * @return int
     */
    public function count()
    {
        return (int) $this->part->totalLinks;
    }
}
