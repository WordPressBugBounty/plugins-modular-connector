<?php

namespace Modular\Connector\Backups\Iron\Jobs;

use Modular\Connector\Backups\Iron\BackupPart;
use Modular\Connector\Backups\Iron\Dump\LargeTableDumper;
use Modular\Connector\Backups\Iron\Dump\SegmentMerger;
use Modular\Connector\Backups\Iron\Dump\SmallTablesDumper;
use Modular\Connector\Backups\Iron\Events\ManagerBackupPartUpdated;
use Modular\Connector\Backups\Iron\Helpers\HasMaxTime;
use Modular\ConnectorDependencies\Illuminate\Bus\Queueable;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldQueue;
use Modular\ConnectorDependencies\Illuminate\Foundation\Bus\Dispatchable;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use function Modular\ConnectorDependencies\dispatch;

/**
 * Multi-tick database dump orchestrator: only the phase state machine and the queue-job
 * lifecycle live here — each phase is its own class under Backups\Iron\Dump.
 *
 * small → SmallTablesDumper: every table under the threshold, one one-shot segment
 * large → LargeTableDumper: one segment per big table, chunked after a lost connection
 * merge → SegmentMerger: segments back into the single .sql, then DatabaseCompressJob
 *
 * Each tick stays inside MAX_TIME and re-dispatches itself with the cursor persisted on the
 * part, so a table too big for one tick — or a provider that kills long dumps — costs a
 * chunk, never the whole dump.
 */
class DatabaseDumpJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;
    use HasMaxTime;

    /**
     * Seconds of work per tick, same budget as the manifest jobs.
     */
    private const MAX_TIME = 25;

    /**
     * @var BackupPart
     */
    public BackupPart $part;

    /**
     * @param BackupPart $part
     */
    public function __construct(BackupPart $part)
    {
        $this->part = $part;
        $this->queue = 'backups';
    }

    /**
     * @throws \Throwable
     * @throws \ErrorException
     */
    public function handle()
    {
        $part = $this->part;

        if ($part->isCancelled()) {
            return;
        }

        $part->markAs(ManagerBackupPartUpdated::STATUS_IN_PROGRESS);

        $startTime = $this->getCurrentTime();
        $shouldStop = fn() => $this->isTimeExceeded($startTime, self::MAX_TIME) || $this->checkMemoryUsage();

        try {
            // Every dispatcher of this job runs after CalculateDatabasePlanJob, so an
            // unplanned part can only be a queue payload serialized by a previous plugin
            // version during an upgrade: fail it explicitly instead of guessing a plan.
            if ($part->dumpPhase === BackupPart::DUMP_PHASE_PLAN) {
                throw new \RuntimeException('Database part was dispatched without a dump plan');
            }

            if ($part->dumpPhase === BackupPart::DUMP_PHASE_SMALL) {
                (new SmallTablesDumper())->dump($part);

                $part->offset = 1;
                $part->dumpPhase = empty($part->dumpTables)
                    ? BackupPart::DUMP_PHASE_MERGE
                    : BackupPart::DUMP_PHASE_LARGE;
            }

            $largeTables = new LargeTableDumper();

            while ($part->dumpPhase === BackupPart::DUMP_PHASE_LARGE) {
                if ($part->dumpTableIndex >= count($part->dumpTables)) {
                    $part->dumpPhase = BackupPart::DUMP_PHASE_MERGE;
                    break;
                }

                if ($shouldStop()) {
                    $this->continueLater($part);

                    return;
                }

                if (!$largeTables->dump($part, $shouldStop)) {
                    $this->continueLater($part);

                    return;
                }

                $part->dumpTableIndex++;
                $part->dumpCursor = [];
                $part->offset++;
            }

            if ($part->dumpPhase === BackupPart::DUMP_PHASE_MERGE) {
                if (!(new SegmentMerger())->merge($part, $shouldStop)) {
                    $this->continueLater($part);

                    return;
                }

                $part->dumpCursor = [];
                $part->dumpPhase = BackupPart::DUMP_PHASE_COMPRESS;
            }

            dispatch(new DatabaseCompressJob($part));
        } catch (\Throwable $e) {
            Log::error($e);

            $part->markAsFailed(ManagerBackupPartUpdated::STATUS_FAILED_EXPORT_DATABASE, $e);
        }
    }

    /**
     * Persist progress and queue the next tick, with the same keepalive semantics as the
     * file pipeline.
     *
     * @param BackupPart $part
     * @return void
     */
    private function continueLater(BackupPart $part): void
    {
        $part->markAs(ManagerBackupPartUpdated::STATUS_IN_PROGRESS, true, ['force_redispatch' => true]);
    }
}
