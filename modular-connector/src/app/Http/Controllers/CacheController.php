<?php

namespace Modular\Connector\Http\Controllers;

use Modular\Connector\Cache\Jobs\CacheClearJob;
use Modular\ConnectorDependencies\Illuminate\Routing\Controller;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Response;
use function Modular\ConnectorDependencies\dispatch;

class CacheController extends Controller
{
    public function clear()
    {
        dispatch(new CacheClearJob());

        return Response::json([
            'success' => 'OK',
        ]);
    }
}
