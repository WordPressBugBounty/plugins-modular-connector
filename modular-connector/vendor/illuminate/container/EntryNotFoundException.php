<?php

namespace Modular\ConnectorDependencies\Illuminate\Container;

use Exception;
use Modular\ConnectorDependencies\Psr\Container\NotFoundExceptionInterface;
class EntryNotFoundException extends Exception implements NotFoundExceptionInterface
{
    //
}
