<?php

namespace Modular\Connector\Cache\Compatibilities;

use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;

class LiteSpeed
{
    public static function available()
    {
        return class_exists('\LiteSpeed\Purge') || class_exists('\LiteSpeed\Purge');
    }

    public static function clear()
    {
        try {
            \LiteSpeed\Purge::purge_all();
        } catch (\Exception $e) {
            Log::error($e, [
                'context' => 'LiteSpeed Cache Clear',
            ]);
        }
    }
}
