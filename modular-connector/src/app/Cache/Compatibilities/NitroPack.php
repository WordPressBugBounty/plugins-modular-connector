<?php

namespace Modular\Connector\Cache\Compatibilities;

use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;

class NitroPack
{
    public static function available()
    {
        return defined('NITROPACK_VERSION');
    }

    public static function clear()
    {
        if (!function_exists('nitropack_purge_cache')) {
            return;
        }

        try {
            nitropack_purge_cache();
        } catch (\Throwable $e) {
            Log::error($e, [
                'context' => 'NitroPack Cache Clear',
            ]);
        }
    }
}
