<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Database;

use Modular\ConnectorDependencies\Illuminate\Database\MySqlConnection as IlluminateMysqlConnection;
use Modular\ConnectorDependencies\Illuminate\Database\QueryException;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
class MysqlConnection extends IlluminateMysqlConnection
{
    use DetectsLostConnections;
    /**
     * How many retries to do (same as wpdb)
     *
     * @var int
     */
    protected $reconnectRetries = 5;
    /**
     * Seconds to wait between attempts
     *
     * @var int
     */
    protected $reconnectSleep = 1;
    /**
     * Handle a query exception that occurred during query execution.
     *
     * @param \Illuminate\Database\QueryException $e
     * @param string $query
     * @param array $bindings
     * @param \Closure $callback
     * @return mixed
     *
     * @throws \Illuminate\Database\QueryException
     */
    protected function tryAgainIfCausedByLostConnection(QueryException $e, $query, $bindings, \Closure $callback)
    {
        if (!$this->causedByLostConnection($e->getPrevious())) {
            throw $e;
        }
        Log::debug('Reconnecting to MySQL database after causedByLostConnection...');
        /**
         * @see wpdb::check_connection()
         */
        for ($i = 1; $i <= $this->reconnectRetries; $i++) {
            $this->reconnect();
            try {
                return $this->runQueryCallback($query, $bindings, $callback);
            } catch (QueryException $e) {
                // If the error is not caused by "gone away" or we have exhausted the retries, rethrow the exception
                if ($i === $this->reconnectRetries || !$this->causedByLostConnection($e->getPrevious())) {
                    throw $e;
                }
                sleep($this->reconnectSleep);
            }
            Log::debug('Retrying MySQL database connection...', ['attempt' => $i, 'max_attempts' => $this->reconnectRetries]);
        }
    }
}
