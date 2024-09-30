<?php

namespace Modular\Connector\Events;

use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldQueue;

class ManagerItemsInstalled extends AbstractEvent implements ShouldQueue
{
}
