<?php

namespace Modular\ConnectorDependencies;

use Modular\ConnectorDependencies\Ares\Framework\Foundation\Application;
/**
 * Get the available container instance.
 *
 * @param string|null $abstract
 * @param array $parameters
 *
 * @return mixed|Application
 *
 * @copyright Taylor Otwell
 * @link      https://github.com/laravel/framework/blob/8.x/src/Illuminate/Foundation/helpers.php
 */
function app(?string $abstract = null, array $parameters = [])
{
    if (\is_null($abstract)) {
        return Application::getInstance();
    }
    return Application::getInstance()->make($abstract, $parameters);
}
