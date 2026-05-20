<?php

namespace Modular\Connector\Cache\Jobs;

use Modular\Connector\Cache\Compatibilities\Breeze;
use Modular\Connector\Cache\Compatibilities\FlyingPress;
use Modular\Connector\Cache\Compatibilities\Flywheel;
use Modular\Connector\Cache\Compatibilities\Kinsta;
use Modular\Connector\Cache\Compatibilities\LiteSpeed;
use Modular\Connector\Cache\Compatibilities\NitroPack;
use Modular\Connector\Cache\Compatibilities\Raidboxes;
use Modular\Connector\Cache\Compatibilities\Varnish;
use Modular\Connector\Cache\Compatibilities\W3TotalCache;
use Modular\Connector\Cache\Compatibilities\WPFastestCache;
use Modular\Connector\Cache\Compatibilities\WPRocket;
use Modular\Connector\Cache\Compatibilities\WPSuperCache;
use Modular\Connector\Events\AbstractEvent;
use Modular\ConnectorDependencies\Illuminate\Bus\Queueable;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldQueue;
use Modular\ConnectorDependencies\Illuminate\Foundation\Bus\Dispatchable;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
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
     * @var array
     */
    private $availableCaches = [
        'nitropack' => NitroPack::class,
        'kinsta' => Kinsta::class,
        'wprocket' => WPRocket::class,
        'breeze' => Breeze::class,
        'flyingpress' => FlyingPress::class,
        'flywheel' => Flywheel::class,
        'litespeed' => LiteSpeed::class,
        'w3totalcache' => W3TotalCache::class,
        'wpsupercache' => WPSuperCache::class,
        'varnish' => Varnish::class,
        'raidboxes' => Raidboxes::class,
        'wp_fastest_cache' => WPFastestCache::class,
    ];

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
        foreach ($this->availableCaches as $key => $cache) {
            $available = $cache::available();

            if (!$available) {
                continue;
            }

            Log::debug("Cache Clear Job", [
                'cache' => $key,
                'message' => "Clearing cache {$key}",
            ]);

            $cache::clear();
        }

        if ($this->eventClass && $this->mrid) {
            $eventClass = $this->eventClass;
            event(new $eventClass($this->mrid, $this->eventPayload));
        }
    }
}
