<?php

namespace Modular\Connector\Events;

use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldQueue;

class ManagerItemsActivated extends AbstractEvent implements ShouldQueue
{
}
