<?php

namespace Modular\ConnectorDependencies\League\Flysystem;

use ErrorException;
class ConnectionErrorException extends ErrorException implements FilesystemException
{
}
