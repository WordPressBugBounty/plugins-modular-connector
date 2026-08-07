<?php

namespace Modular\Connector\Backups\Iron\Dump;

use Modular\Connector\Backups\Iron\BackupPart;
use Modular\Connector\Facades\Manager;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Database\DetectsLostConnections;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Storage;

/**
 * One large table into its own segment: a one-shot single-table dump first, switched
 * permanently to the chunked dumper when the provider drops the connection mid-stream.
 *
 * Progress lives in the part's cursor, so the caller can stop between chunks and resume on
 * the next tick.
 */
class LargeTableDumper
{
    use DetectsLostConnections;

    /**
     * How many lost connections a single chunked table absorbs before the part fails. The
     * cursor makes every retry resume from the last committed chunk, so a transient blip
     * costs one chunk; a server that is genuinely down still fails fast.
     */
    private const CHUNK_MAX_RETRIES = 3;

    /**
     * Dump the table at the part's current index. Returns true when the table finished,
     * false when $shouldStop cut the tick short and the cursor was persisted.
     *
     * @param BackupPart $part
     * @param callable $shouldStop
     * @return bool
     * @throws \Throwable
     */
    public function dump(BackupPart $part, callable $shouldStop): bool
    {
        $table = $part->dumpTables[$part->dumpTableIndex];
        $segment = $part->getSegmentFileName($part->dumpTableIndex + 1);
        $path = Storage::disk('backups')->path($segment);

        if (!empty($part->dumpCursor['chunked'])) {
            return $this->dumpChunks($part, $table, $path, $shouldStop);
        }

        try {
            Manager::driver('database')->dumpSegment($path, $part, [$table['name']]);

            return true;
        } catch (\Throwable $e) {
            if (!$this->causedByLostConnection($e)) {
                throw $e;
            }

            // The provider killed the streaming dump mid-table: from here on this table is
            // dumped in bounded chunks, which no timeout or killer can outlast.
            Log::warning('Single-table dump lost the connection, switching to chunked dump', [
                'table' => $table['name'],
                'size' => $table['size'],
                'error' => $e->getMessage(),
            ]);

            Storage::disk('backups')->delete($segment);

            $part->dumpCursor = ['chunked' => true];

            return $this->dumpChunks($part, $table, $path, $shouldStop);
        }
    }

    /**
     * @param BackupPart $part
     * @param array $table
     * @param string $path
     * @param callable $shouldStop
     * @return bool
     * @throws \Throwable
     */
    private function dumpChunks(BackupPart $part, array $table, string $path, callable $shouldStop): bool
    {
        $cursor = $part->dumpCursor;

        try {
            $finished = Manager::driver('database')->chunkedDumper($part)->dumpTable(
                $path,
                $table['name'],
                $cursor,
                $table['avgRow'],
                $shouldStop
            );

            $part->dumpCursor = $cursor;

            return $finished;
        } catch (\Throwable $e) {
            // The cursor only ever points at the last fully written chunk, so it is safe to
            // persist even when the dumper died mid-chunk.
            $part->dumpCursor = $cursor;

            $retries = isset($cursor['retries']) ? (int)$cursor['retries'] : 0;

            if (!$this->causedByLostConnection($e) || $retries >= self::CHUNK_MAX_RETRIES) {
                throw $e;
            }

            $part->dumpCursor['retries'] = $retries + 1;

            Log::warning('Chunked dump lost the connection, resuming from cursor on the next tick', [
                'table' => $table['name'],
                'retries' => $retries + 1,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
