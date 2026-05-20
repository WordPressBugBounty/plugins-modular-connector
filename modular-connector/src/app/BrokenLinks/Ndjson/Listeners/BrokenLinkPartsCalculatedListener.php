<?php

namespace Modular\Connector\BrokenLinks\Ndjson\Listeners;

use Modular\Connector\BrokenLinks\Ndjson\BrokenLinkPart;
use Modular\Connector\BrokenLinks\Ndjson\Events\ManagerBrokenLinkPartsCalculated;
use Modular\Connector\BrokenLinks\Ndjson\Events\ManagerBrokenLinkPartUpdated;
use Modular\Connector\BrokenLinks\Ndjson\Jobs\ProcessBrokenLinkBatchJob;
use function Modular\ConnectorDependencies\dispatch;

/**
 * Reacts to the initial plan emission and dispatches one ProcessBrokenLinkBatchJob
 * per PENDING part. Keeps `NdjsonDriver::scan()` focused on "build the plan and
 * publish it" — every downstream side effect lives in a listener.
 *
 * Mirrors ScanPartsCalculatedListener.
 */
class BrokenLinkPartsCalculatedListener
{
    public function handle(ManagerBrokenLinkPartsCalculated $event): void
    {
        $event->parts
            ->where('status', ManagerBrokenLinkPartUpdated::STATUS_PENDING)
            ->each(fn(BrokenLinkPart $part) => dispatch(new ProcessBrokenLinkBatchJob($part)));
    }
}
