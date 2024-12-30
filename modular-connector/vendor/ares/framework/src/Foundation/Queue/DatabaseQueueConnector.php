<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Queue;

use Modular\ConnectorDependencies\Illuminate\Queue\Connectors\ConnectorInterface;
class DatabaseQueueConnector implements ConnectorInterface
{
    /**
     * @var \wpdb
     */
    protected $connection;
    /**
     * Create a new connector instance.
     *
     * @param \wpdb $wpdb
     */
    public function __construct(\wpdb $wpdb)
    {
        $this->connection = $wpdb;
    }
    /**
     * @param array $config
     * @return DatabaseQueue
     */
    public function connect(array $config)
    {
        return new DatabaseQueue($this->connection, $config['table'], $config['prefix'], $config['queue'], $config['retry_after']);
    }
}
