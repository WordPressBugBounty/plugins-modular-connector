<?php

namespace Modular\ConnectorDependencies\Illuminate\Support\Facades;

/**
 * @method static void setUpProcess(callable $callback)
 * @method static void setUpTestCase(callable $callback)
 * @method static void setUpTestDatabase(callable $callback)
 * @method static void tearDownProcess(callable $callback)
 * @method static void tearDownTestCase(callable $callback)
 * @method static int|false token()
 *
 * @see \Illuminate\Testing\ParallelTesting
 * @internal
 */
class ParallelTesting extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return \Modular\ConnectorDependencies\Illuminate\Testing\ParallelTesting::class;
    }
}
