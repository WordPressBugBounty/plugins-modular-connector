<?php

namespace Modular\Connector\Events;

use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldQueue;

class RestorationSetupUpdated extends AbstractEvent implements ShouldQueue
{
}
