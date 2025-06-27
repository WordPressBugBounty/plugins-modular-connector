<?php

namespace Modular\Connector\Http\Middleware;

use Modular\ConnectorDependencies\Ares\Framework\Foundation\Auth\JWT;
use Modular\ConnectorDependencies\Illuminate\Http\Request;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Response;
use function Modular\ConnectorDependencies\abort;
use function Modular\ConnectorDependencies\app;

class AuthenticateLoopback
{

    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param \Closure $next
     * @return mixed
     * @throws \Exception
     */
    public function handle($request, \Closure $next)
    {
        $action = app()->getScheduleHook();

        if ($request->hasHeader('Authentication')) {
            $authHeader = $request->header('Authentication', '');

            if (!JWT::verify($authHeader, $action)) {
                Log::debug('Invalid JWT for schedule hook', [
                    'hook' => $action,
                    'header' => $authHeader,
                ]);

                abort(Response::json(['error' => sprintf('Invalid JWT for %s', $action)], 403));
            }
        } else {
            $isValid = check_ajax_referer($action, 'nonce', false);

            if (!$isValid) {
                Log::debug('Invalid nonce for schedule hook', [
                    'hook' => $action,
                    'nonce' => $request->input('nonce'),
                ]);

                abort(Response::json(['error' => sprintf('Invalid NONCE for %s', $action)], 403));
            }
        }

        return $next($request);
    }
}
