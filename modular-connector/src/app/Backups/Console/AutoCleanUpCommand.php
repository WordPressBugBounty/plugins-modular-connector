<?php

namespace Modular\Connector\Backups\Console;

use Modular\Connector\Backups\Facades\Backup;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Console\Command;
use Modular\ConnectorDependencies\Carbon\Carbon;
use Modular\ConnectorDependencies\Illuminate\Support\Collection;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\File as FileFacade;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;

class AutoCleanUpCommand extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'modular:cleanup {--max-files=10: Maximum files to clean}
                                            {--max-age=1 : The number of days to keep the files}';

    protected $description = 'Remove orphan backup files';

    /**
     * @return void
     */
    public function handle()
    {
        try {
            $path = Backup::path('*');

            $files = Collection::make(FileFacade::glob($path))
                ->filter(function ($file) {
                    return FileFacade::isFile($file) &&
                        !in_array(FileFacade::basename($file), ['index.html', 'index.php', '.htaccess', 'web.config']) &&
                        FileFacade::lastModified($file) < Carbon::now()->subDays($this->option('max-age'))->timestamp;
                });

            $maxFilesToClean = $this->option('max-files');
            $files = $files->slice(0, $maxFilesToClean)->toArray();

            // delete the previous
            if (!empty($files)) {
                FileFacade::delete($files);
            }
        } catch (\Throwable $e) {
            // Silence is golden
            Log::error($e);
        }
    }
}
