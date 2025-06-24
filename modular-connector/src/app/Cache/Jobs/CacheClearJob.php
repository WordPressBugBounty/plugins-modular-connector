<?php

namespace Modular\Connector\Cache\Jobs;

use Modular\Connector\Cache\Compatibilities\Breeze;
use Modular\Connector\Cache\Compatibilities\FlyingPress;
use Modular\Connector\Cache\Compatibilities\Flywheel;
use Modular\Connector\Cache\Compatibilities\Kinsta;
use Modular\Connector\Cache\Compatibilities\LiteSpeed;
use Modular\Connector\Cache\Compatibilities\NitroPack;
use Modular\Connector\Cache\Compatibilities\Varnish;
use Modular\Connector\Cache\Compatibilities\W3TotalCache;
use Modular\Connector\Cache\Compatibilities\WPFastestCache;
use Modular\Connector\Cache\Compatibilities\WPRocket;
use Modular\Connector\Cache\Compatibilities\WPSuperCache;
use Modular\ConnectorDependencies\Illuminate\Bus\Queueable;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldQueue;
use Modular\ConnectorDependencies\Illuminate\Foundation\Bus\Dispatchable;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;

class CacheClearJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable;
    use Queueable;

    public $uniqueFor = 3600;

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
        'wp_fastest_cache' => WPFastestCache::class,
    ];

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
    }
}
