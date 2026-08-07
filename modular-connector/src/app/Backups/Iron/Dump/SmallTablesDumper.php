<?php

namespace Modular\Connector\Backups\Iron\Dump;

use Modular\Connector\Backups\Iron\BackupPart;
use Modular\Connector\Facades\Manager;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Storage;

/**
 * Segment 0: every table below the size threshold, dumped in one shot — the path that has
 * always worked. Blocking, so it is not interruptible; the caller checks the tick budget
 * around it, not inside.
 */
class SmallTablesDumper
{
    /**
     * @param BackupPart $part
     * @return void
     * @throws \Throwable
     */
    public function dump(BackupPart $part): void
    {
        $filename = $part->getSegmentFileName(0);

        Storage::disk('backups')->put($filename, '');

        Manager::driver('database')->dumpSegment(Storage::disk('backups')->path($filename), $part);
    }
}
