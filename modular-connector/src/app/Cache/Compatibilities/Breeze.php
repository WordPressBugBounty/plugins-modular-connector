<?php

namespace Modular\Connector\Cache\Compatibilities;

use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;

class Breeze
{
    public static function available()
    {
        return defined('BREEZE_VERSION');
    }

    public static function clear()
    {
        try {
            do_action('breeze_clear_all_cache');
            do_action('breeze_clear_varnish');
        } catch (\Exception $e) {
            Log::error($e, [
                'context' => 'Breeze Cache Clear',
            ]);
        }
    }
}
