<?php

namespace Modular\ConnectorDependencies\Illuminate\Queue\Console;

use Modular\ConnectorDependencies\Carbon\Carbon;
use Modular\ConnectorDependencies\Illuminate\Bus\BatchRepository;
use Modular\ConnectorDependencies\Illuminate\Bus\DatabaseBatchRepository;
use Modular\ConnectorDependencies\Illuminate\Bus\PrunableBatchRepository;
use Modular\ConnectorDependencies\Illuminate\Console\Command;
class PruneBatchesCommand extends Command
{
    /**
     * The console command signature.
     *
     * @var string
     */
    protected $signature = 'queue:prune-batches
                {--hours=24 : The number of hours to retain batch data}
                {--unfinished= : The number of hours to retain unfinished batch data }';
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Prune stale entries from the batches database';
    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $repository = $this->laravel[BatchRepository::class];
        $count = 0;
        if ($repository instanceof PrunableBatchRepository) {
            $count = $repository->prune(Carbon::now()->subHours($this->option('hours')));
        }
        $this->info("{$count} entries deleted!");
        if ($unfinished = $this->option('unfinished')) {
            $count = 0;
            if ($repository instanceof DatabaseBatchRepository) {
                $count = $repository->pruneUnfinished(Carbon::now()->subHours($this->option('unfinished')));
            }
            $this->info("{$count} unfinished entries deleted!");
        }
    }
}
