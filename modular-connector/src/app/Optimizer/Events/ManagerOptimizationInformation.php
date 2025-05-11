<?php

namespace Modular\Connector\Optimizer\Events;

use Modular\Connector\Events\AbstractEvent;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldQueue;

class ManagerOptimizationInformation extends AbstractEvent implements ShouldQueue
{

}
