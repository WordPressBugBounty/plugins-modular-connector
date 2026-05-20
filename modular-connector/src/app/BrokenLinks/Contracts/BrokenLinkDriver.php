<?php

namespace Modular\Connector\BrokenLinks\Contracts;

/**
 * Contract every broken-links driver implements. All actions are async: the
 * driver receives a payload via options() and dispatches queue jobs that emit
 * progress events the API consumes via webhook listeners.
 *
 * Progress is reported per-part: `ManagerBrokenLinkPartsCalculated` once with
 * the initial plan, then `ManagerBrokenLinkPartUpdated` per (step, status)
 * transition. step ∈ {scan, upload}; status ∈ {pending, in_progress, done,
 * failed, excluded}. The canonical terminal is `step=upload, status=done`.
 * There is no aggregate "scan completed" event — the API derives it from the
 * part terminals.
 */
interface BrokenLinkDriver
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
     * Wire broken-links domain events to listeners. Called once at boot.
     *
     * @return void
     */
    public function listeners();

    /**
     * Kick off a broken-links discovery scan: validate input, resolve targets and
     * dispatch the batch loop that streams discovered URLs to NDJSON. Returns
     * immediately; progress emitted via events.
     *
     * @return void
     */
    public function scan();
}
