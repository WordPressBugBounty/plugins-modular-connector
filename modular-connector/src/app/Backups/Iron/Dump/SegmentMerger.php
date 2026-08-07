<?php

namespace Modular\Connector\Backups\Iron\Dump;

use Modular\Connector\Backups\Iron\BackupPart;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Storage;

/**
 * Concatenates the dump segments, in plan order, into the single .sql the rest of the
 * pipeline (zip, upload, restore) has always consumed.
 *
 * Merging reads and writes the whole dump (2x its size in I/O) — far beyond one tick on
 * ~1MB/s hosting — so it resumes by cursor: segment index, source offset, and committed
 * target bytes (ftruncate on resume, same crash-safety as the chunked dumper). Each segment
 * is deleted once fully merged, keeping peak disk at one dump plus one segment.
 */
class SegmentMerger
{
    /**
     * Copied per block so the merge respects the tick budget like every other phase.
     */
    private const BLOCK_BYTES = 4 * 1024 * 1024;

    /**
     * Returns true when the merge is complete, false when $shouldStop cut the tick short
     * and the cursor was persisted for the next tick.
     *
     * @param BackupPart $part
     * @param callable $shouldStop
     * @return bool
     * @throws \Throwable
     */
    public function merge(BackupPart $part, callable $shouldStop): bool
    {
        $disk = Storage::disk('backups');
        $count = count($part->dumpTables) + 1;
        $filename = $part->getFileNameWithExtension('sql');

        if (!isset($part->dumpCursor['segment'])) {
            // A finished merge deletes every segment before the phase advances; if the tick
            // died between those two steps, the merged .sql is already there.
            if (!$disk->exists($part->getSegmentFileName(0)) && $disk->exists($filename)) {
                return true;
            }

            // Single segment: the merge is just a rename.
            if ($count === 1) {
                $disk->move($part->getSegmentFileName(0), $filename);

                return true;
            }

            // All segments must exist before the first byte is copied: a hole means one was
            // lost, and failing beats storing a dump with missing tables as a good backup.
            for ($index = 0; $index < $count; $index++) {
                if (!$disk->exists($part->getSegmentFileName($index))) {
                    throw new \RuntimeException('Dump segment missing: ' . $part->getSegmentFileName($index));
                }
            }

            $part->dumpCursor = ['segment' => 0, 'source' => 0, 'bytes' => 0];
        }

        $cursor = $part->dumpCursor;

        $target = fopen($disk->path($filename), 'c+b');

        if ($target === false) {
            throw new \RuntimeException('Cannot open dump file for merge: ' . $filename);
        }

        try {
            ftruncate($target, $cursor['bytes']);
            fseek($target, $cursor['bytes']);

            while ($cursor['segment'] < $count) {
                $segment = $part->getSegmentFileName($cursor['segment']);
                $source = fopen($disk->path($segment), 'rb');

                if ($source === false) {
                    throw new \RuntimeException('Cannot read dump segment: ' . $segment);
                }

                try {
                    fseek($source, $cursor['source']);

                    while (!feof($source)) {
                        $block = fread($source, self::BLOCK_BYTES);

                        if ($block === false) {
                            throw new \RuntimeException('Failed reading dump segment: ' . $segment);
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
                }

                $cursor = ['segment' => $cursor['segment'] + 1, 'source' => 0, 'bytes' => $cursor['bytes']];
                $part->dumpCursor = $cursor;

                $disk->delete($segment);
            }
        } finally {
            fclose($target);
        }

        return true;
    }
}
