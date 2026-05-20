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
}
