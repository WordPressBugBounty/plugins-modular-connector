<?php

namespace Modular\Connector\Security\Contracts;

/**
 * Contract every scan driver implements. All actions are async: the driver
 * receives a payload via options() and dispatches queue jobs that emit
 * progress events the API consumes via webhook listeners.
 *
 * Per-scan progress is driven by the plugin's polling loop (PollImunifyJob);
 * terminal states emit `ManagerScanCompleted` / `ManagerScanCancelled`.
 */
interface ScanDriver
{
    /**
     * Configure the driver for the next operation. Fluent — chain into the action.
     *
     * @param string $requestId
     * @param \stdClass $payload
     * @return $this
     */
    public function options($requestId, $payload);

    /**
     * Wire scan-domain events to listeners. Called once at boot.
     *
     * @return void
     */
    public function listeners();

    /**
     * Kick off a malware scan: enumerate files, build per-disk manifests and submit
     * to the scan provider. If `database` is in `included`, also dispatches the DB
     * scan flow. Returns immediately; progress emitted via events.
     *
     * @return void
     */
    public function scan();
}
