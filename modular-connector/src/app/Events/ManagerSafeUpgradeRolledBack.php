<?php

namespace Modular\Connector\Events;

use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldQueue;

class ManagerSafeUpgradeRolledBack extends AbstractEvent implements ShouldQueue
{
}
