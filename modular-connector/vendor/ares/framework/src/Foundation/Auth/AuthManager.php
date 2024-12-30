<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Auth;

use Modular\ConnectorDependencies\Illuminate\Contracts\Auth\Factory as FactoryContract;
class AuthManager implements FactoryContract
{
    /**
     * The application instance.
     *
     * @var \Illuminate\Contracts\Foundation\Application
     */
    protected $app;
    /**
     * The registered custom driver creators.
     *
     * @var array
     */
    protected $customCreators = [];
    /**
     * The array of created "drivers".
     *
     * @var array
     */
    protected $guards = [];
    /**
     * The user resolver shared by various services.
     *
     * Determines the default user for Gate, Request, and the Authenticatable contract.
     *
     * @var \Closure
     */
    protected $userResolver;
    /**
     * Create a new Auth manager instance.
     *
     * @param \Illuminate\Contracts\Foundation\Application $app
     * @return void
     */
    public function __construct($app)
    {
        $this->app = $app;
        $this->userResolver = fn($guard = null) => $this->guard($guard)->user();
    }
    /**
     * Set the default authentication driver name.
     *
     * @param string $name
     * @return void
     */
    public function setDefaultDriver($name)
    {
        $this->app['config']['auth.defaults.guard'] = $name;
    }
    /**
     * Get the default authentication driver name.
     *
     * @return string
     */
    public function getDefaultDriver()
    {
        return $this->app['config']['auth.defaults.guard'];
    }
    /**
     * Get the guard configuration.
     *
     * @param string $name
     * @return array
     */
    protected function getConfig($name)
    {
        return $this->app['config']["auth.guards.{$name}"];
    }
    /**
     * Attempt to get the guard from the local cache.
     *
     * @param string|null $name
     * @return \Illuminate\Contracts\Auth\Guard|\Illuminate\Contracts\Auth\StatefulGuard
     */
    public function guard($name = null)
    {
        $name = $name ?: $this->getDefaultDriver();
        return $this->guards[$name] ?? $this->guards[$name] = $this->resolve($name);
    }
    /**
     * Call a custom driver creator.
     *
     * @param string $name
     * @param array $config
     * @return mixed
     */
    protected function callCustomCreator($name, array $config)
    {
        return $this->customCreators[$config['driver']]($this->app, $name, $config);
    }
    /**
     * Resolve the given guard.
     *
     * @param string $name
     * @return \Illuminate\Contracts\Auth\Guard|\Illuminate\Contracts\Auth\StatefulGuard
     *
     * @throws \InvalidArgumentException
     */
    protected function resolve($name)
    {
        $config = $this->getConfig($name);
        if (is_null($config)) {
            throw new \InvalidArgumentException("Auth guard [{$name}] is not defined.");
        }
        if (isset($this->customCreators[$config['driver']])) {
            return $this->callCustomCreator($name, $config);
        }
        $driverMethod = 'create' . ucfirst($config['driver']) . 'Driver';
        if (method_exists($this, $driverMethod)) {
            return $this->{$driverMethod}($name, $config);
        }
        throw new \InvalidArgumentException("Auth driver [{$config['driver']}] for guard [{$name}] is not defined.");
    }
    /**
     * Set the default guard driver the factory should serve.
     *
     * @param string $name
     * @return void
     */
    public function shouldUse($name)
    {
        $name = $name ?: $this->getDefaultDriver();
        $this->setDefaultDriver($name);
        $this->userResolver = fn($name = null) => $this->guard($name)->user();
    }
    public function createModularDriver()
    {
        return new ModularGuard();
    }
}
