<?php

namespace Modular\Connector\Cache\Compatibilities;

use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;

class Flywheel
{
    public static function available()
    {
        return class_exists('FlywheelNginxCompat');
    }

    public static function clear()
    {
        try {
            Varnish::purge();
        } catch (\Throwable $e) {
            Log::error($e, [
                'context' => 'Flywheel Cache Clear',
            ]);
        }
    }
}
