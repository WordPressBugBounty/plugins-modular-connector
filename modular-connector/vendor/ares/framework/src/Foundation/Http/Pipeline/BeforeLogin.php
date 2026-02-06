<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Http\Pipeline;

use Modular\ConnectorDependencies\Ares\Framework\Foundation\Compatibilities\LoginCompatibilities;
use Closure;
use Modular\ConnectorDependencies\Illuminate\Http\Request;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
/**
 * Execute compatibility fixes before login.
 *
 * This pipe executes hosting-specific compatibility fixes that must run
 * before the user login process (e.g., WP Engine, Pressable workarounds).
 */
class BeforeLogin
{
    /**
     * Handle pre-login compatibility fixes.
     *
     * @param Request $request
     * @param Closure $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        LoginCompatibilities::beforeLogin();
        return $next($request);
    }
}
