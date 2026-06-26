<?php

namespace Modular\Connector\Security\Imunify\Listeners;

use Modular\Connector\Backups\Iron\Manifest\CalculateManifestJob;
use Modular\Connector\Security\Facades\Scan;
use Modular\Connector\Security\Imunify\Events\ManagerScanPartsCalculated;
use Modular\Connector\Security\Imunify\Events\ManagerScanPartUpdated;
use Modular\Connector\Security\Imunify\Jobs\ScanDbJob;
use Modular\Connector\Security\Imunify\ScanPart;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Storage;
use function Modular\ConnectorDependencies\dispatch;

/**
 * Reacts to the initial plan emission: registers the expected per-disk parts with
 * the fan-in coordinator and dispatches the producer jobs (CalculateManifestJob
 * per pending file disk; ScanDbJob for the database part).
 *
 * Keeps `ImunifyDriver::scan()` focused on "build the plan and publish it" — every
 * downstream side effect lives in a listener.
 */
class ScanPartsCalculatedListener
{
    /**
     * @param ManagerScanPartsCalculated $event
     * @return void
     */
    public function handle(ManagerScanPartsCalculated $event)
    {
        $manifestPending = $event->parts
            ->where('status', ManagerScanPartUpdated::STATUS_PENDING)
            ->where('type', '!=', ScanPart::INCLUDE_DATABASE);

        // Prime the fan-in coordinator BEFORE dispatching any job so the first
        // CalculateManifestJob that completes finds the expected-list in cache.
        // (Both happen inside the synchronous event() lifecycle, but the order
        // here is what guarantees no race when the queue runs immediately.)
        ScanManifestCoordinatorListener::registerExpected(
            (int)$event->payload['site_scan'],
            $manifestPending->pluck('type')->all()
        );

        if ($manifestPending->isNotEmpty()) {
            // Mark the files flow as active BEFORE any dispatch so the DB flow's
            // cleanup (if it terminates first) can see it and keep `_scan_options_`
            // in place for the still-running files flow.
            Scan::markFilesActive((int)$event->payload['site_scan']);

            Log::debug('ScanPartsCalculatedListener: File scan triggered', [
                'mrid' => $event->mrid,
                'parts' => $manifestPending->pluck('type')->all(),
            ]);
        }

        $manifestPending->each(function (ScanPart $part) {
            Storage::disk('scans')->put($part->manifestPath, '');

            dispatch(new CalculateManifestJob($part));
        });

        $databasePart = $event->parts
            ->where('type', ScanPart::INCLUDE_DATABASE)
            ->where('status', ManagerScanPartUpdated::STATUS_PENDING)
            ->first();

        if ($databasePart) {
            Scan::markDatabaseActive((int)$databasePart->siteScan);

            Log::debug('ScanPartsCalculatedListener: DB scan triggered', [
                'mrid' => $event->mrid,
                'site_scan' => $databasePart->siteScan,
                'excluded_tables' => count($databasePart->excludedTables),
            ]);

            dispatch(new ScanDbJob($databasePart));
        }
    }
}
