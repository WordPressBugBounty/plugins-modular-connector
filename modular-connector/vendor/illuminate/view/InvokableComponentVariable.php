<?php

namespace Modular\ConnectorDependencies\Illuminate\View;

use ArrayIterator;
use Closure;
use Modular\ConnectorDependencies\Illuminate\Contracts\Support\DeferringDisplayableValue;
use Modular\ConnectorDependencies\Illuminate\Support\Enumerable;
use IteratorAggregate;
class InvokableComponentVariable implements DeferringDisplayableValue, IteratorAggregate
{
    /**
     * The callable instance to resolve the variable value.
     *
     * @var \Closure
     */
    protected $callable;
    /**
     * Create a new variable instance.
     *
     * @param  \Closure  $callable
     * @return void
     */
    public function __construct(Closure $callable)
    {
        $this->callable = $callable;
    }
    /**
     * Resolve the displayable value that the class is deferring.
     *
     * @return \Illuminate\Contracts\Support\Htmlable|string
     */
    public function resolveDisplayableValue()
    {
        return $this->__invoke();
    }
    /**
     * Get an interator instance for the variable.
     *
     * @return \ArrayIterator
     */
    #[\ReturnTypeWillChange]
    public function getIterator()
    {
        $result = $this->__invoke();
        return new ArrayIterator($result instanceof Enumerable ? $result->all() : $result);
    }
    /**
     * Dynamically proxy attribute access to the variable.
     *
     * @param  string  $key
     * @return mixed
     */
    public function __get($key)
    {
        return $this->__invoke()->{$key};
    }
    /**
     * Dynamically proxy method access to the variable.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        return $this->__invoke()->{$method}(...$parameters);
    }
    /**
     * Resolve the variable.
     *
     * @return mixed
     */
    public function __invoke()
    {
        return call_user_func($this->callable);
    }
    /**
     * Resolve the variable as a string.
     *
     * @return mixed
     */
    public function __toString()
    {
        return (string) $this->__invoke();
    }
}
