<?php

namespace Modular\ConnectorDependencies\Illuminate\Contracts\Container;

use Exception;
use Modular\ConnectorDependencies\Psr\Container\ContainerExceptionInterface;
class CircularDependencyException extends Exception implements ContainerExceptionInterface
{
    //
}
