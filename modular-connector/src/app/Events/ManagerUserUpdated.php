<?php

namespace Modular\Connector\Events;

use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldQueue;

class ManagerUserUpdated extends AbstractEvent implements ShouldQueue
{
}
