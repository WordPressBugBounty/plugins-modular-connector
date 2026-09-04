<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Database;

use Modular\ConnectorDependencies\Illuminate\Support\Str;
use RuntimeException;
use Throwable;
/**
 * Thrown when MySQL refuses to open a new connection because the account or the server
 * ran out of connection slots (1203, 1040, 1226).
 *
 * This is a fatal condition for the current process: the pool is saturated and nothing
 * we do in-process frees a slot. Callers must abort and release the turn so the next
 * cron cycle can retry against a healthier server.
 */
class ConnectionRefusedException extends RuntimeException
{
    /**
     * @param \Throwable $previous
     * @return static
     */
    public static function from(Throwable $previous): self
    {
        return new static(sprintf('MySQL refused a new connection: %s', $previous->getMessage()), (int) $previous->getCode(), $previous);
    }
    /**
     * Determine whether the given throwable reports a refused connection.
     *
     * Walks the previous-exception chain because PDO errors reach us wrapped in a
     * QueryException.
     *
     * @param \Throwable|null $e
     * @return bool
     */
    public static function matches(?Throwable $e): bool
    {
        while (!is_null($e)) {
            $matches = Str::contains($e->getMessage(), [
                // 1203 - user already has more than 'max_user_connections' active connections
                'max_user_connections',
                'has exceeded the',
                // 1040 - too many connections (server wide)
                'Too many connections',
                'too many connections',
                // 1226 - user has exceeded the 'max_connections_per_hour' resource
                'max_connections_per_hour',
                'max_queries_per_hour',
                'max_updates_per_hour',
            ]);
            if ($matches) {
                return \true;
            }
            $e = $e->getPrevious();
        }
        return \false;
    }
}
