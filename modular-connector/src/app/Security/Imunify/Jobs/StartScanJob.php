<?php

namespace Modular\Connector\Security\Imunify\Jobs;

use Modular\Connector\Security\Facades\Scan;
use Modular\ConnectorDependencies\Illuminate\Bus\Queueable;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldQueue;
use Modular\ConnectorDependencies\Illuminate\Foundation\Bus\Dispatchable;

class StartScanJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    /**
     * @var string
     */
    protected $requestId;

    /**
     * @var \stdClass
     */
    protected $payload;

    /**
     * Pure constructor: only stores the payload (sanitized) and queue. Secrets
     * and options are persisted to cache by `SecurityController::scan()` before
     * dispatch, so this constructor has no side-effects and is safe to call
     * from unit tests.
     *
     * @param string $requestId
     * @param \stdClass $payload
     */
    public function __construct($requestId, \stdClass $payload)
    {
        $this->requestId = $requestId;
        $this->queue = 'backups';

        $sanitized = clone $payload;
        unset($sanitized->site_key, $sanitized->webhook_secret);
        $this->payload = $sanitized;
    }

    /**
     * @return void
     */
    public function handle()
    {
        $name = trim($this->payload->name, '"');

        if (Scan::isFilesCancelled($name)) {
            return;
        }

        // Secrets validation (and the all-excluded fallback when missing) lives in
        // ImunifyDriver::scan() so it can emit ManagerScanPartsCalculated cleanly.
        Scan::options($this->requestId, $this->payload)->scan();
    }

    /**
     * Nothing has been emitted yet when this job dies (a malformed payload,
     * a storage error building the parts), so API would wait forever. Close
     * both flows with the reason — whichever the payload included, a terminal
     * `cancelled` is what it needs.
     *
     * @param \Throwable|null $e
     * @return void
     */
    public function failed(\Throwable $e = null)
    {
        $name = isset($this->payload->name) ? trim((string)$this->payload->name, '"') : '';
        $siteScan = isset($this->payload->site_scan) ? (int)$this->payload->site_scan : 0;
        $error = $e ? 'start_failed: ' . $e->getMessage() : 'start_failed';

        Scan::cancelFiles($this->requestId, $name, $siteScan, $error);
        Scan::cancelDatabase($this->requestId, $name, $siteScan, $error);
    }
}
