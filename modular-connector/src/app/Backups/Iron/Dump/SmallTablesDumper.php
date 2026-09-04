<?php

namespace Modular\Connector\Backups\Iron\Dump;

use Modular\Connector\Backups\Iron\BackupPart;
use Modular\Connector\Facades\Manager;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Database\DetectsLostConnections;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Storage;

/**
 * Segment 0: every table below the size threshold.
 *
 * The one-shot dump comes first — the path that has always worked, and by far the fastest.
 * It is blocking, so the tick budget is checked around it, not inside. When it loses the
 * connection the segment is rebuilt table by table through the chunked dumper, resuming
 * across ticks: the size threshold decides which tables are worth their own segment, it
 * cannot decide which ones a host will let us dump in one go.
 */
class SmallTablesDumper
{
    use DetectsLostConnections;

    /**
     * Lost connections a single chunked table absorbs before the part fails, same budget
     * as LargeTableDumper.
     */
    private const CHUNK_MAX_RETRIES = 3;

    /**
     * Bytes copied per block when folding a finished table into segment 0.
     */
    private const APPEND_BLOCK_BYTES = 4 * 1024 * 1024;

    /**
     * Returns true when segment 0 is complete, false when the tick ran out of budget and
     * the cursor was persisted for the next one.
     *
     * @param BackupPart $part
     * @param callable $shouldStop
     * @return bool
     * @throws \Throwable
     */
    public function dump(BackupPart $part, callable $shouldStop): bool
    {
        $filename = $part->getSegmentFileName(0);
        $path = Storage::disk('backups')->path($filename);

        if ($part->dumpSmallChunked) {
            return $this->dumpTables($part, $filename, $path, $shouldStop);
        }

        Storage::disk('backups')->put($filename, '');

        try {
            Manager::driver('database')->dumpSegment($path, $part);

            return true;
        } catch (\Throwable $e) {
            if (!$this->causedByLostConnection($e)) {
                throw $e;
            }

            Log::warning('Small tables dump lost the connection, switching to chunked dump', [
                'error' => $e->getMessage(),
            ]);

            // A one-shot dump is a single unresumable stream: its partial output tells us
            // nothing about which tables made it, so segment 0 starts over from empty.
            Storage::disk('backups')->put($filename, '');

            $part->dumpSmallChunked = true;
            $part->dumpSmallTableIndex = 0;
            $part->dumpCursor = [];

            return $this->dumpTables($part, $filename, $path, $shouldStop);
        }
    }

    /**
     * Dump the remaining small tables one by one into segment 0.
     *
     * @param BackupPart $part
     * @param string $filename
     * @param string $segmentPath
     * @param callable $shouldStop
     * @return bool
     * @throws \Throwable
     */
    private function dumpTables(BackupPart $part, string $filename, string $segmentPath, callable $shouldStop): bool
    {
        if (empty($part->dumpSmallTables)) {
            $part->dumpSmallTables = $this->resolveTables($part);
        }

        $dumper = Manager::driver('database')->chunkedDumper($part);
        $total = count($part->dumpSmallTables);

        while ($part->dumpSmallTableIndex < $total) {
            if ($shouldStop()) {
                return false;
            }

            $table = $part->dumpSmallTables[$part->dumpSmallTableIndex];
            $scratch = $part->getSmallScratchFileName($part->dumpSmallTableIndex);
            $scratchPath = Storage::disk('backups')->path($scratch);

            // Two steps per table, each resumable on its own: dump it to a scratch file,
            // then fold that file into segment 0.
            if (($part->dumpCursor['step'] ?? 'dump') === 'dump') {
                if (!$this->dumpTable($dumper, $part, $table, $scratchPath, $shouldStop)) {
                    return false;
                }

                $part->dumpCursor = [
                    'step' => 'append',
                    'source' => 0,
                    'bytes' => Storage::disk('backups')->size($filename),
                ];
            }

            if (!$this->appendScratch($part, $scratchPath, $segmentPath, $shouldStop)) {
                return false;
            }

            Storage::disk('backups')->delete($scratch);

            $part->dumpSmallTableIndex++;
            $part->dumpCursor = [];
        }

        return true;
    }

    /**
     * One table into its own scratch file, with the same bounded retry LargeTableDumper uses.
     *
     * @param \Modular\Connector\Backups\Dumper\ChunkedDumper $dumper
     * @param BackupPart $part
     * @param array $table
     * @param string $scratchPath
     * @param callable $shouldStop
     * @return bool
     * @throws \Throwable
     */
    private function dumpTable($dumper, BackupPart $part, array $table, string $scratchPath, callable $shouldStop): bool
    {
        $cursor = $part->dumpCursor;

        try {
            $finished = $dumper->dumpTable($scratchPath, $table['name'], $cursor, $table['avgRow'], $shouldStop);

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

            Log::warning('Small table chunked dump lost the connection, resuming from cursor on the next tick', [
                'table' => $table['name'],
                'retries' => $retries + 1,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Append a finished scratch file into segment 0, resumable by byte cursor and truncating
     * back to the last committed byte, exactly like SegmentMerger does.
     *
     * @param BackupPart $part
     * @param string $scratchPath
     * @param string $segmentPath
     * @param callable $shouldStop
     * @return bool
     */
    private function appendScratch(BackupPart $part, string $scratchPath, string $segmentPath, callable $shouldStop): bool
    {
        $cursor = $part->dumpCursor;

        $source = fopen($scratchPath, 'rb');

        if ($source === false) {
            throw new \RuntimeException('Cannot read dump scratch file: ' . $scratchPath);
        }

        $target = fopen($segmentPath, 'c+b');

        if ($target === false) {
            fclose($source);

            throw new \RuntimeException('Cannot open dump segment: ' . $segmentPath);
        }

        try {
            ftruncate($target, $cursor['bytes']);
            fseek($target, $cursor['bytes']);
            fseek($source, $cursor['source']);

            while (!feof($source)) {
                $block = fread($source, self::APPEND_BLOCK_BYTES);

                if ($block === false) {
                    throw new \RuntimeException('Failed reading dump scratch file: ' . $scratchPath);
                }

                if ($block !== '') {
                    fwrite($target, $block);
                    fflush($target);

                    $cursor['source'] += strlen($block);
                    $cursor['bytes'] = ftell($target);
                    $part->dumpCursor = $cursor;
                }

                if ($shouldStop()) {
                    return false;
                }
            }
        } finally {
            fclose($source);
            fclose($target);
        }

        return true;
    }

    /**
     * Every table that belongs in segment 0: the ones neither excluded nor planned as a
     * segment of their own.
     *
     * @param BackupPart $part
     * @return array
     */
    private function resolveTables(BackupPart $part): array
    {
        $excluded = array_merge($part->excludedTables, array_column($part->dumpTables, 'name'));

        return Manager::driver('database')->tree()
            ->filter(fn($table) => !in_array($table->name, $excluded))
            ->sortBy('name')
            ->values()
            ->map(fn($table) => [
                'name' => $table->name,
                'avgRow' => (int)$table->avg_row,
            ])
            ->toArray();
    }
}
