<?php

namespace Modular\Connector\Cache\Compatibilities;

use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;

class W3TotalCache
{
    public static function available()
    {
        return defined('W3TC');
    }

    public static function clear()
    {
        if (!function_exists('w3tc_flush_all')) {
            return;
        }

        try {
            w3tc_flush_all();
        } catch (\Throwable $e) {
            Log::error($e, [
                'context' => 'W3 Total Cache Clear',
            ]);
        }
    }
}
