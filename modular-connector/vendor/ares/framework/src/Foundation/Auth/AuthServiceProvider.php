<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Auth;

use Modular\ConnectorDependencies\Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Modular\ConnectorDependencies\Illuminate\Support\ServiceProvider;
class AuthServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->registerAuthenticator();
        $this->registerUserResolver();
        $this->registerRequestRebindHandler();
        $this->registerEventRebindHandler();
    }
    /**
     * Register the authenticator services.
     *
     * @return void
     */
    protected function registerAuthenticator()
    {
        $this->app->singleton('auth', fn($app) => new AuthManager($app));
        $this->app->singleton('auth.driver', fn($app) => $app['auth']->guard());
    }
    /**
     * Register a resolver for the authenticated user.
     *
     * @return void
     */
    protected function registerUserResolver()
    {
        $this->app->bind(AuthenticatableContract::class, fn($app) => call_user_func($app['auth']->userResolver()));
    }
    /**
     * Handle the re-binding of the request binding.
     *
     * @return void
     */
    protected function registerRequestRebindHandler()
    {
        $this->app->rebinding('request', function ($app, $request) {
            $request->setUserResolver(function ($guard = null) use ($app) {
                return call_user_func($app['auth']->userResolver(), $guard);
            });
        });
    }
    /**
     * Handle the re-binding of the event dispatcher binding.
     *
     * @return void
     */
    protected function registerEventRebindHandler()
    {
        $this->app->rebinding('events', function ($app, $dispatcher) {
            if (!$app->resolved('auth') || $app['auth']->hasResolvedGuards() === \false) {
                return;
            }
            if (method_exists($guard = $app['auth']->guard(), 'setDispatcher')) {
                $guard->setDispatcher($dispatcher);
            }
        });
    }
}
