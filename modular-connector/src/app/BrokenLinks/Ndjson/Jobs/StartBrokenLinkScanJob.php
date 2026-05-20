<?php

namespace Modular\Connector\BrokenLinks\Ndjson\Jobs;

use Modular\Connector\BrokenLinks\Facades\BrokenLink;
use Modular\ConnectorDependencies\Illuminate\Bus\Queueable;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldQueue;
use Modular\ConnectorDependencies\Illuminate\Foundation\Bus\Dispatchable;

/**
 * Entry point of the BLC discovery pipeline. Mirrors StartScanJob: a thin
 * queued shim that hands the request to the driver via the facade so the
 * Manager picks the right driver implementation. Failure surface here is
 * essentially zero (instantiate + dispatch), so no `failed()` handler — the
 * real failure stages are `batch` (ProcessBrokenLinkBatchJob) and `upload`
 * (UploadBrokenLinkJob).
 */
class StartBrokenLinkScanJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable;
    use Queueable;

    /** @var string */
    protected $requestId;

    /** @var \stdClass */
    protected $payload;

    public function __construct(string $requestId, \stdClass $payload)
    {
        $this->requestId = $requestId;
        $this->payload = $payload;
    }

    public function handle(): void
    {
        BrokenLink::options($this->requestId, $this->payload)->scan();
    }

    /**
     * Lock key per `site_broken_link_scan` — two parallel scans on different
     * ids are independent, two start dispatches for the same scan are not.
     */
    public function uniqueId()
    {
        return (string) $this->payload->site_broken_link_scan;
    }
}
