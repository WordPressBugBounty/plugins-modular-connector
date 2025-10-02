<?php

namespace Modular\Connector\Backups\Console;

use Modular\Connector\Backups\Facades\Backup;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Console\Command;
use Modular\ConnectorDependencies\Carbon\Carbon;
use Modular\ConnectorDependencies\Illuminate\Support\Collection;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\File as FileFacade;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Storage;

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
                ->filter(
                    fn($file) => FileFacade::isFile($file) &&
                        !in_array(FileFacade::basename($file), ['index.html', 'index.php', '.htaccess', 'web.config']) &&
                        FileFacade::lastModified($file) < Carbon::now()->subDays($this->option('max-age'))->timestamp
                );

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

        try {
            $path = Storage::disk('content')->path('upgrade-temp-backup/plugins/*');

            $dirs = Collection::make(FileFacade::glob($path))
                ->filter(
                    fn($file) => FileFacade::isDirectory($file) &&
                        in_array(FileFacade::basename($file), [FileFacade::dirname(MODULAR_CONNECTOR_BASENAME)]) &&
                        FileFacade::lastModified($file) < Carbon::now()->subDays($this->option('max-age'))->timestamp
                );

            $maxFilesToClean = $this->option('max-files');

            $dirs->slice(0, $maxFilesToClean)
                ->each(fn($dir) => FileFacade::deleteDirectory($dir));
        } catch (\Throwable $e) {
            // Silence is golden
            Log::error($e);
        }

        // Delete old safe updates
        try {
            $path = Storage::disk('safe_upgrades')->path('*');

            $dirs = Collection::make(FileFacade::glob($path))
                ->filter(
                    fn($file) => FileFacade::isDirectory($file) &&
                        FileFacade::lastModified($file) < Carbon::now()->subDays(7)->timestamp
                );

            $dirs->slice(0, $maxFilesToClean)
                ->each(fn($dir) => FileFacade::deleteDirectory($dir));
        } catch (\Throwable $e) {
            Log::error($e);
        }
    }
}
