<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Queue\Database;

use Modular\ConnectorDependencies\Illuminate\Queue\Connectors\DatabaseConnector as IlluminateDatabaseConnector;
class DatabaseConnector extends IlluminateDatabaseConnector
{
    /**
     * Establish a queue connection.
     *
     * @param array $config
     * @return \Illuminate\Contracts\Queue\Queue
     */
    public function connect(array $config)
    {
        return new DatabaseQueue($this->connections->connection($config['connection'] ?? null), $config['table'], $config['queue'], $config['retry_after'] ?? 60, $config['after_commit'] ?? null);
    }
}
