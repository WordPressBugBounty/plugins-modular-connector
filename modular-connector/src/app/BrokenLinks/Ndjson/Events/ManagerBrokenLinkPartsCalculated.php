<?php

namespace Modular\Connector\BrokenLinks\Ndjson\Events;

use Modular\Connector\BrokenLinks\Ndjson\BrokenLinkPart;
use Modular\Connector\Events\AbstractEvent;
use Modular\ConnectorDependencies\Illuminate\Support\Collection;

/**
 * Initial plan event: one row per public post-type with its status (pending or
 * excluded). The API consumes this to know which parts to expect and which to
 * mark terminal up-front. Mirrors ManagerBackupPartsCalculated / ManagerScanPartsCalculated.
 */
class ManagerBrokenLinkPartsCalculated extends AbstractEvent
{
    /**
     * In-process side-channel: the actual BrokenLinkPart objects, so the
     * dispatcher listener can act on the real parts (dispatch ProcessBrokenLinkBatchJob)
     * instead of reconstructing them from the wire payload.
     *
     * HookSendEventJob serializes only `mrid` + `payload`, so this collection
     * never leaks to the webhook.
     *
     * @var Collection<BrokenLinkPart>
     */
    public $parts;

    /**
     * @param Collection<BrokenLinkPart> $parts
     */
    public function __construct(Collection $parts)
    {
        $this->parts = $parts;

        $first = $parts->first();
        $mrid = $first ? $first->mrid : '';
        $siteBrokenLinkScan = $first ? $first->siteBrokenLinkScan : 0;

        parent::__construct($mrid, [
            'site_broken_link_scan' => $siteBrokenLinkScan,
            'parts' => $parts->map(fn(BrokenLinkPart $part) => [
                'source_type' => $part->postType,
                'step' => $part->step,
                'status' => $part->status,
                'total_sources' => $part->processedCount,
                'total_links' => $part->totalLinks,
            ])->values()->toArray(),
        ]);
    }
}
