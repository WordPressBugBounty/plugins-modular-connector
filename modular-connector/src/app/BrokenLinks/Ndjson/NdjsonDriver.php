<?php

namespace Modular\Connector\BrokenLinks\Ndjson;

use Modular\Connector\BrokenLinks\Contracts\BrokenLinkDriver;
use Modular\Connector\BrokenLinks\Ndjson\Events\ManagerBrokenLinkPartsCalculated;
use Modular\Connector\BrokenLinks\Ndjson\Events\ManagerBrokenLinkPartUpdated;
use Modular\Connector\BrokenLinks\Ndjson\Listeners\BrokenLinkPartsCalculatedListener;
use Modular\Connector\BrokenLinks\Ndjson\Listeners\BrokenLinkUploadDispatcherListener;
use Modular\Connector\Listeners\HookEventListener;
use Modular\ConnectorDependencies\Illuminate\Support\Collection;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Event;
use function Modular\ConnectorDependencies\event;

class NdjsonDriver implements BrokenLinkDriver
{
    /** @var \stdClass */
    protected $payload;

    /** @var string */
    protected $requestId;

    /**
     * Wire BLC events. Each event has two roles:
     *   - HookEventListener: forward to the manager via /site/manager/hook.
     *   - In-process orchestrator: dispatch the next job in the pipeline.
     *
     * Event-driven shape (mirrors ImunifyDriver::listeners):
     *   PartsCalculated → HookEventListener + BrokenLinkPartsCalculatedListener
     *                     (dispatches ProcessBrokenLinkBatchJob per PENDING part)
     *   PartUpdated     → HookEventListener + BrokenLinkUploadDispatcherListener
     *                     (filters step=upload status=pending, dispatches UploadBrokenLinkJob)
     */
    public function listeners()
    {
        Event::listen(ManagerBrokenLinkPartsCalculated::class, HookEventListener::class);
        Event::listen(ManagerBrokenLinkPartsCalculated::class, BrokenLinkPartsCalculatedListener::class);

        Event::listen(ManagerBrokenLinkPartUpdated::class, HookEventListener::class);
        Event::listen(ManagerBrokenLinkPartUpdated::class, BrokenLinkUploadDispatcherListener::class);
    }

    public function options($requestId, $payload)
    {
        $this->requestId = $requestId;
        $this->payload = $payload;

        return $this;
    }

    /**
     * Build one BrokenLinkPart per public post-type and publish the plan. The
     * dispatching of the producer jobs is owned by BrokenLinkPartsCalculatedListener
     * reacting to the event below — this method only builds and publishes.
     */
    public function scan()
    {
        $postTypes = Collection::make(
            array_filter(get_post_types([], 'names'), 'is_post_type_viewable')
        )->values();

        $parts = $postTypes->map(
            fn($postType) => (new BrokenLinkPart($this->requestId))
                ->setType((string)$postType)
                ->setPayload($this->payload)
                ->setManifestPath()
                ->calculateExclusion()
        );

        event(new ManagerBrokenLinkPartsCalculated($parts));
    }
}
