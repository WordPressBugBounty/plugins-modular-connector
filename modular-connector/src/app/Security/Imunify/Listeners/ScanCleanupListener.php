<?php

namespace Modular\Connector\Security\Imunify\Listeners;

use Modular\Connector\Security\Facades\Scan;
use Modular\Connector\Security\Imunify\Events\ManagerScanCancelled;
use Modular\Connector\Security\Imunify\Events\ManagerScanCompleted;
use Modular\Connector\Security\Imunify\Events\ManagerScanPartUpdated;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;

/**
 * Wipes the per-flow cache bucket after a flow reaches a terminal state.
 * Replaces the cache-cleanup responsibility that lived inside the deleted
 * Finalize/Fail reactive jobs.
 *
 * Each event carries `$type` (files | database) so the listener can route
 * to the matching cleanup method without inspecting the payload array.
 */
class ScanCleanupListener
{
    /**
     * @param ManagerScanCancelled $event
     * @return void
     */
    public function onCancelled(ManagerScanCancelled $event)
    {
        Log::debug('ScanCleanupListener: Flow cancelled, wiping cache', [
            'mrid' => $event->mrid,
            'site_scan' => $event->siteScan,
            'type' => $event->type,
            'error' => $event->payload['error'] ?? null,
        ]);

        $this->forgetForType($event->type, $event->siteScan);
    }

    /**
     * @param ManagerScanCompleted $event
     * @return void
     */
    public function onCompleted(ManagerScanCompleted $event)
    {
        Log::debug('ScanCleanupListener: Flow completed, wiping cache', [
            'mrid' => $event->mrid,
            'site_scan' => $event->siteScan,
            'type' => $event->type,
        ]);

        $this->forgetForType($event->type, $event->siteScan);
    }

    /**
     * @param string $type
     * @param int $siteScan
     * @return void
     */
    private function forgetForType($type, $siteScan)
    {
        if ($type === ManagerScanPartUpdated::TYPE_DATABASE) {
            Scan::forgetDatabaseCache($siteScan);
        }

        if ($type === ManagerScanPartUpdated::TYPE_FILES) {
            Scan::forgetFilesCache($siteScan);
        }
    }
}
