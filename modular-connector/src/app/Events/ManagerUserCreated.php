<?php

namespace Modular\Connector\Events;

use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldQueue;

class ManagerUserCreated extends AbstractEvent implements ShouldQueue
{
}
