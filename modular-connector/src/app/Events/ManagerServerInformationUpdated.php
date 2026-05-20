<?php

namespace Modular\Connector\Events;

use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldQueue;

class ManagerServerInformationUpdated extends AbstractEvent implements ShouldQueue
{
}
