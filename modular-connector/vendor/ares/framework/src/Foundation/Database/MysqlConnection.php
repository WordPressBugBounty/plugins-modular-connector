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
        // A refused connection is not a lost one: the pool is full and reconnecting in a loop
        // only keeps this process queueing for a slot. Abort so the caller can release the turn.
        if ($this->causedByConnectionRefused($e)) {
            Log::warning('MySQL refused a new connection, aborting without retry.', ['error' => $e->getMessage()]);
            throw ConnectionRefusedException::from($e);
        }
        $cause = $e->getPrevious();
        if (is_null($cause) || !$this->causedByLostConnection($cause)) {
            throw $e;
        }
        Log::debug('Reconnecting to MySQL database after causedByLostConnection...');
        /**
         * @see wpdb::check_connection()
         */
        for ($i = 1; $i <= $this->reconnectRetries; $i++) {
            try {
                // reconnect() itself opens a socket, so a saturated pool surfaces here first.
                $this->reconnect();
                return $this->runQueryCallback($query, $bindings, $callback);
            } catch (\Throwable $e) {
                if ($this->causedByConnectionRefused($e)) {
                    Log::warning('MySQL refused a new connection while reconnecting, aborting.', ['attempt' => $i]);
                    throw ConnectionRefusedException::from($e);
                }
                $previous = $e instanceof QueryException ? $e->getPrevious() : $e;
                // If the error is not caused by "gone away" or we have exhausted the retries, rethrow the exception
                if ($i === $this->reconnectRetries || is_null($previous) || !$this->causedByLostConnection($previous)) {
                    throw $e;
                }
                sleep($this->reconnectSleep);
            }
            Log::debug('Retrying MySQL database connection...', ['attempt' => $i, 'max_attempts' => $this->reconnectRetries]);
        }
    }
}
