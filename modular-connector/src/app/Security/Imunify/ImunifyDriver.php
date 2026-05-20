<?php

namespace Modular\Connector\Security\Imunify;

use Modular\Connector\Listeners\HookEventListener;
use Modular\Connector\Security\Contracts\ScanDriver;
use Modular\Connector\Security\Facades\Scan;
use Modular\Connector\Security\Imunify\Events\ManagerScanCancelled;
use Modular\Connector\Security\Imunify\Events\ManagerScanCompleted;
use Modular\Connector\Security\Imunify\Events\ManagerScanFilesMergeUpdated;
use Modular\Connector\Security\Imunify\Events\ManagerScanPartsCalculated;
use Modular\Connector\Security\Imunify\Events\ManagerScanPartUpdated;
use Modular\Connector\Security\Imunify\Events\ManagerScanProgressUpdated;
use Modular\Connector\Security\Imunify\Listeners\ScanCleanupListener;
use Modular\Connector\Security\Imunify\Listeners\ScanFilesSubmitDispatcherListener;
use Modular\Connector\Security\Imunify\Listeners\ScanManifestCoordinatorListener;
use Modular\Connector\Security\Imunify\Listeners\ScanPartsCalculatedListener;
use Modular\ConnectorDependencies\Illuminate\Support\Collection;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Event;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use function Modular\ConnectorDependencies\event;

class ImunifyDriver implements ScanDriver
{
    /**
     * @var \stdClass
     */
    protected $payload;

    /**
     * @var string
     */
    protected $requestId;

    /**
     * Wire scan-domain events to their listeners. Called once at boot.
     *
     * Every event below is forwarded to the Modular API as a webhook by
     * HookEventListener. Some also carry in-process orchestration responsibilities
     * via a second listener — those events are the seams of the pipeline.
     *
     * Public events (webhook + optional orchestration):
     *   - ManagerScanPartsCalculated → HookEventListener
     *                                + ScanPartsCalculatedListener (registers expected
     *                                  parts and dispatches CalculateManifestJob /
     *                                  ScanDbJob — the producers of the pipeline).
     *   - ManagerScanPartUpdated → HookEventListener
     *                            + ScanManifestCoordinatorListener (fan-in: dispatches
     *                              MergeScanManifestsJob when every expected per-disk
     *                              manifest is terminal).
     *   - ManagerScanFilesMergeUpdated → HookEventListener
     *                                   + ScanFilesSubmitDispatcherListener
     *                                     (filters status=done, dispatches SubmitToImunifyJob —
     *                                     no intermediate "upload to API" step, polling
     *                                     resolves file_index → path/checksum locally).
     *   - ManagerScanCancelled → HookEventListener
     *                          + ScanCleanupListener@onCancelled (wipes per-flow cache).
     *   - ManagerScanCompleted → HookEventListener
     *                          + ScanCleanupListener@onCompleted (wipes per-flow cache).
     *
     * Each pipeline-step job emits its "completed" event and forgets about the next
     * step; the dispatcher listener owns the transition.
     *
     * @return void
     */
    public function listeners()
    {
        Event::listen(ManagerScanPartsCalculated::class, HookEventListener::class);
        Event::listen(ManagerScanPartsCalculated::class, ScanPartsCalculatedListener::class);

        Event::listen(ManagerScanPartUpdated::class, HookEventListener::class);
        Event::listen(ManagerScanPartUpdated::class, ScanManifestCoordinatorListener::class);

        Event::listen(ManagerScanFilesMergeUpdated::class, HookEventListener::class);
        Event::listen(ManagerScanFilesMergeUpdated::class, ScanFilesSubmitDispatcherListener::class);

        Event::listen(ManagerScanProgressUpdated::class, HookEventListener::class);

        Event::listen(ManagerScanCancelled::class, HookEventListener::class);
        Event::listen(ManagerScanCancelled::class, ScanCleanupListener::class . '@onCancelled');

        Event::listen(ManagerScanCompleted::class, HookEventListener::class);
        Event::listen(ManagerScanCompleted::class, ScanCleanupListener::class . '@onCompleted');
    }

    /**
     * Configure the driver for the next operation. Fluent — chain into the action.
     *
     * @param string $requestId
     * @param \stdClass $payload
     * @return $this
     */
    public function options($requestId, $payload)
    {
        $this->payload = $payload;
        $this->requestId = $requestId;

        return $this;
    }

    /**
     * Entry point: build the 7 ScanParts (6 file disks + database) and publish the
     * plan via `ManagerScanPartsCalculated`. The producer jobs (CalculateManifestJob
     * and ScanDbJob) are dispatched by `ScanPartsCalculatedListener` reacting to
     * that event — this method only builds and publishes.
     *
     * @return void
     */
    public function scan()
    {
        // Secrets are populated by StartScanJob::storeSecrets() at construction time,
        // so an empty cache here means the entry was evicted or the cache backend
        // lost data. Inform API by reporting every part as excluded so it can clean
        // up state instead of waiting for events that will never come, and mark the
        // scan as cancelled so any straggler job exits early through isCancelled().
        $siteScan = (int)$this->payload->site_scan;

        if (Scan::siteKey() === null) {
            Log::error('ImunifyDriver: Secrets missing, cancelling scan with all parts excluded', [
                'requestId' => $this->requestId,
                'site_scan' => $siteScan,
            ]);

            Scan::cancel(trim($this->payload->name, '"'));

            event(new ManagerScanPartsCalculated($this->buildAllExcludedParts()));

            return;
        }

        // 7 possible parts: 6 file disks + database. Each becomes a ScanPart so the
        // ManagerScanPartsCalculated event reports the full picture (which run, which
        // are excluded). The database part runs an independent flow (ScanDbJob) and
        // does not feed the file-manifest merge coordinator.
        $parts = $this->allTypes()->map(
            fn($type) => (new ScanPart($this->requestId))
                ->setType($type)
                ->setPayload($this->payload)
                ->setManifestPath()
                ->calculateExclusion()
        );

        event(new ManagerScanPartsCalculated($parts));
    }

    /**
     * Canonical list of part types: 6 file disks + database.
     *
     * @return Collection<string>
     */
    private function allTypes(): Collection
    {
        return Collection::make([
            ScanPart::INCLUDE_DATABASE,
            ScanPart::INCLUDE_CORE,
            ScanPart::INCLUDE_PLUGINS,
            ScanPart::INCLUDE_THEMES,
            ScanPart::INCLUDE_MU_PLUGINS,
            ScanPart::INCLUDE_CONTENT,
            ScanPart::INCLUDE_UPLOADS,
        ]);
    }

    /**
     * Build all 7 parts with status pre-set to EXCLUDED. Used when the scan cannot
     * proceed (e.g. missing secrets) but we still need to deliver a coherent
     * ManagerScanPartsCalculated event to the API.
     *
     * @return Collection<ScanPart>
     */
    private function buildAllExcludedParts(): Collection
    {
        return $this->allTypes()->map(function ($type) {
            $part = (new ScanPart($this->requestId))
                ->setType($type)
                ->setPayload($this->payload)
                ->setManifestPath();

            $part->status = ManagerScanPartUpdated::STATUS_EXCLUDED;

            return $part;
        });
    }
}
