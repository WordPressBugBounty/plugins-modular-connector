<?php

namespace Modular\Connector\Events;

use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldQueue;

class ManagerSafeUpgradeCleanedUp extends AbstractEvent implements ShouldQueue
{
}
