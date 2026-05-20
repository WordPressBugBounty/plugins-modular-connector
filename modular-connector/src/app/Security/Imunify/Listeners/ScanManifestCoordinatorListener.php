<?php

namespace Modular\Connector\Security\Imunify\Listeners;

use Modular\Connector\Security\Facades\Scan;
use Modular\Connector\Security\Imunify\Events\ManagerScanPartUpdated;
use Modular\Connector\Security\Imunify\Jobs\MergeScanManifestsJob;
use Modular\Connector\Security\Imunify\ScanPart;
use Modular\ConnectorDependencies\Carbon\Carbon;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Cache;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use function Modular\ConnectorDependencies\dispatch;

/**
 * Fan-in coordinator for the per-disk manifest phase.
 *
 * Listens to every `ManagerScanPartUpdated` event. When a per-disk event reaches
 * a terminal manifest state, the listener checks whether every expected disk has
 * reported (via `_scan_parts_status_<site_scan>`, written by `ScanPart::markAs()`)
 * and either dispatches `MergeScanManifestsJob` (if at least one disk produced data)
 * or emits a global `step=scan, status=failed` so API can clean up.
 *
 * Responsibility split:
 *   - `ScanPart::markAs()` publishes the part's terminal record.
 *   - This listener reads the records and decides "should we advance?".
 */
class ScanManifestCoordinatorListener
{
    /**
     * Register the expected list of part types for coordination. Called by
     * `ImunifyDriver::scan()` after `calculateExclusion()` has narrowed the set.
     *
     * Keyed by `site_scan` so the coordination cache survives across the multiple
     * site requests the API will fire for the same scan (the original `mrid`
     * differs from the one carried by `/action`, `/files_need_action`, etc.).
     *
     * @param int $siteScan
     * @param array $types
     * @return void
     */
    public static function registerExpected($siteScan, array $types)
    {
        Cache::put(
            sprintf('_scan_parts_expected_%s', $siteScan),
            array_values($types),
            Carbon::now()->addDay()
        );
    }

    /**
     * @param ManagerScanPartUpdated $event
     * @return void
     */
    public function handle(ManagerScanPartUpdated $event)
    {
        // $part is set only by forPart() — i.e. per-disk manifest events.
        // Aggregate/global events (type=files/database) are not our concern here.
        if ($event->part === null) {
            return;
        }

        $status = $event->payload['status'];

        if (!ManagerScanPartUpdated::isManifestTerminal($status)) {
            return;
        }

        $this->onPartTerminal($event->part);
    }

    /**
     * @param ScanPart $part
     * @return void
     */
    private function onPartTerminal(ScanPart $part)
    {
        $expected = Cache::get(sprintf('_scan_parts_expected_%s', $part->siteScan), []);

        if (empty($expected)) {
            // Legitimate when:
            //   (a) Setup phase — ImunifyDriver::scan() runs calculateExclusion()
            //       on every part *before* ScanPartsCalculatedListener registers
            //       the expected list, so an EXCLUDED part lands here while the
            //       cache key still doesn't exist.
            //   (b) All-excluded scan — registerExpected() was called with an
            //       empty list; there is nothing to coordinate either way.
            // Either way, no merge is owed to anyone — return.
            return;
        }

        // Read the snapshot of parts published so far by ScanPart::markAs().
        // Every entry is terminal by construction: markAs only writes here when
        // the new status is terminal, and this cache is written nowhere else.
        $partsStatus = Cache::get(sprintf('_scan_parts_status_%s', $part->siteScan), []);

        foreach ($expected as $expectedType) {
            if (!isset($partsStatus[$expectedType])) {
                return;
            }
        }

        $hasManifestData = false;

        foreach ($partsStatus as $info) {
            if ($info['status'] === ManagerScanPartUpdated::STATUS_DONE && $info['total_items'] > 0) {
                $hasManifestData = true;
                break;
            }
        }

        if (!$hasManifestData) {
            Log::debug('ScanManifestCoordinatorListener: No manifest data to merge (all parts excluded or failed)', [
                'mrid' => $part->mrid,
                'parts_status' => array_map(function ($info) {
                    return $info['status'];
                }, $partsStatus),
            ]);

            // Emit the per-flow Cancelled event so API marks files terminal;
            // ScanCleanupListener wipes the per-flow cache in reaction.
            Scan::cancelFiles(
                $part->mrid,
                $part->name,
                $part->siteScan,
                'no_manifest_data'
            );

            return;
        }

        Log::debug('ScanManifestCoordinatorListener: All parts manifest done, dispatching merge', [
            'mrid' => $part->mrid,
            'parts' => array_keys($partsStatus),
        ]);

        dispatch(new MergeScanManifestsJob(
            $part->mrid,
            $part->name,
            $part->siteScan,
            $partsStatus
        ));
    }
}
