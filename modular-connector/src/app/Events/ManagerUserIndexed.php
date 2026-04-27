<?php

namespace Modular\Connector\Events;

use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldQueue;

class ManagerUserIndexed extends AbstractEvent implements ShouldQueue
{
}
