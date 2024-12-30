<?php

namespace Modular\ConnectorDependencies\Illuminate\Queue\Connectors;

use Modular\ConnectorDependencies\Illuminate\Queue\NullQueue;
class NullConnector implements ConnectorInterface
{
    /**
     * Establish a queue connection.
     *
     * @param  array  $config
     * @return \Illuminate\Contracts\Queue\Queue
     */
    public function connect(array $config)
    {
        return new NullQueue();
    }
}
