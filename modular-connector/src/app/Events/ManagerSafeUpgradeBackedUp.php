<?php

namespace Modular\Connector\Events;

use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldQueue;

class ManagerSafeUpgradeBackedUp extends AbstractEvent implements ShouldQueue
{
}
