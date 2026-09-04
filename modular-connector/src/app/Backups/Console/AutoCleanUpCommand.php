<?php

namespace Modular\Connector\Backups\Console;

use Modular\Connector\Backups\Facades\Backup;
use Modular\Connector\Helper\PrivateStorage;
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
                                            {--max-age=1 : The number of days to keep the files}
                                            {--max-age-scans=3 : The number of days to keep orphan scan artifacts}';

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

        // Sweep orphan scan artifacts (BLC NDJSON, Imunify per-disk manifests
        // and consolidated NDJSON). Active scans keep their manifest fresh via
        // touch() each tick, so a larger window here avoids cancelling long
        // scans that legitimately exceed the default backups window.
        try {
            $path = Storage::disk('scans')->path('*');

            $files = Collection::make(FileFacade::glob($path))
                ->filter(
                    fn($file) => FileFacade::isFile($file) &&
                        !in_array(FileFacade::basename($file), ['index.html', 'index.php', '.htaccess', 'web.config']) &&
                        FileFacade::lastModified($file) < Carbon::now()->subDays($this->option('max-age-scans'))->timestamp
                );

            $files = $files->slice(0, $maxFilesToClean)->toArray();

            if (!empty($files)) {
                FileFacade::delete($files);
            }
        } catch (\Throwable $e) {
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

        // Sweep logs written under a name this install no longer uses: the old
        // predictable one, and any left behind by a salt rotation. Monolog only
        // rotates the current token, so nothing else would ever remove them and
        // they stay readable over HTTP on servers that ignore our .htaccess.
        try {
            $current = basename(PrivateStorage::logPath(), '.log');

            $files = Collection::make(FileFacade::glob(PrivateStorage::logGlob()))
                ->filter(fn($file) => FileFacade::isFile($file) && PrivateStorage::isForeign($file, $current))
                ->slice(0, $this->option('max-files'))
                ->toArray();

            if (!empty($files)) {
                FileFacade::delete($files);
            }
        } catch (\Throwable $e) {
            Log::error($e);
        }

        // Same for the folders this install no longer writes to: the pre-token ones
        // (including wp-content/modular_backups, which sat outside the storage root)
        // and whatever a salt rotation orphaned. The bootstrap only ever creates the
        // current folder, so this is the single mechanism that retires the rest.
        //
        // A day of grace rather than none: a folder still being written to keeps its
        // mtime fresh, so a token that somehow resolved differently in another context
        // cannot take live data with it. Backup parts are transient anyway — they are
        // uploaded and then swept on --max-age, so there is nothing here to preserve.
        try {
            $cutoff = Carbon::now()->subDay()->timestamp;

            foreach (['views', 'cache', 'upgrades', 'scans', 'backups'] as $name) {
                Collection::make(PrivateStorage::staleFolders($name))
                    ->filter(fn($dir) => FileFacade::lastModified($dir) < $cutoff)
                    ->slice(0, $this->option('max-files'))
                    ->each(fn($dir) => FileFacade::deleteDirectory($dir));
            }
        } catch (\Throwable $e) {
            Log::error($e);
        }
    }
}
