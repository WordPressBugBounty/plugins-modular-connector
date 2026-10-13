<?php

namespace Modular\Connector\Cache\Jobs;

use Modular\Connector\Cache\CachePurger;
use Modular\Connector\Events\AbstractEvent;
use Modular\ConnectorDependencies\Illuminate\Bus\Queueable;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldQueue;
use Modular\ConnectorDependencies\Illuminate\Foundation\Bus\Dispatchable;
use function Modular\ConnectorDependencies\event;

class CacheClearJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable;
    use Queueable;

    public $uniqueFor = 3600;

    /**
     * Event class to fire after cache clearing completes.
     *
     * @var class-string<AbstractEvent>|null
     */
    protected ?string $eventClass;

    /**
     * @var string|null
     */
    protected ?string $mrid;

    /**
     * @var mixed
     */
    protected $eventPayload;

    /**
     * @param class-string<AbstractEvent>|null $eventClass
     * @param string|null $mrid
     * @param mixed $eventPayload
     */
    public function __construct(?string $eventClass = null, ?string $mrid = null, $eventPayload = null)
    {
        $this->eventClass = $eventClass;
        $this->mrid = $mrid;
        $this->eventPayload = $eventPayload;
    }

    /**
     * @return void
     */
    public function handle()
    {
        CachePurger::purgeAll();

        if ($this->eventClass && $this->mrid) {
            $eventClass = $this->eventClass;
            event(new $eventClass($this->mrid, $this->eventPayload));
        }
    }
}
