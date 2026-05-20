<?php

namespace Modular\Connector\Security\Imunify\Events;

use Modular\Connector\Events\AbstractEvent;
use Modular\Connector\Security\Imunify\ScanPart;
use Modular\ConnectorDependencies\Illuminate\Support\Collection;

class ManagerScanPartsCalculated extends AbstractEvent
{
    /**
     * In-process side-channel: the actual ScanPart objects, so listeners can act
     * on the real parts (dispatch CalculateManifestJob, register expected types,
     * etc.) instead of reconstructing them from the wire payload.
     *
     * HookSendEventJob serializes only `mrid` + `payload`, so this collection
     * never leaks to the webhook.
     *
     * @var Collection<ScanPart>
     */
    public $parts;

    /**
     * @param Collection<ScanPart> $parts
     */
    public function __construct(Collection $parts)
    {
        $this->parts = $parts;

        $first = $parts->first();
        $mrid = $first->mrid;
        $siteScan = $first->siteScan;

        parent::__construct($mrid, [
            'site_scan' => $siteScan,
            'parts' => $parts->map(fn(ScanPart $part) => [
                'type' => $part->type,
                'offset' => 0,
                'total_items' => $part->totalItems ?: 0,
                'status' => $part->status,
            ])->values()->toArray(),
        ]);
    }
}
