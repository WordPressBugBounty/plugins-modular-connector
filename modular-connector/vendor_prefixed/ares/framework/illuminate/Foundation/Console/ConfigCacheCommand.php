<?php

namespace Modular\ConnectorDependencies\Illuminate\Foundation\Console;

use Modular\ConnectorDependencies\Illuminate\Console\Command;
use Modular\ConnectorDependencies\Illuminate\Contracts\Console\Kernel as ConsoleKernelContract;
use Modular\ConnectorDependencies\Illuminate\Filesystem\Filesystem;
use LogicException;
use Throwable;
/** @internal */
class ConfigCacheCommand extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'config:cache';
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a cache file for faster configuration loading';
    /**
     * The filesystem instance.
     *
     * @var \Illuminate\Filesystem\Filesystem
     */
    protected $files;
    /**
     * Create a new config cache command instance.
     *
     * @param  \Illuminate\Filesystem\Filesystem  $files
     * @return void
     */
    public function __construct(Filesystem $files)
    {
        parent::__construct();
        $this->files = $files;
    }
    /**
     * Execute the console command.
     *
     * @return void
     *
     * @throws \LogicException
     */
    public function handle()
    {
        $this->call('config:clear');
        $config = $this->getFreshConfiguration();
        $configPath = $this->laravel->getCachedConfigPath();
        $this->files->put($configPath, '<?php return ' . \var_export($config, \true) . ';' . \PHP_EOL);
        try {
            require $configPath;
        } catch (Throwable $e) {
            $this->files->delete($configPath);
            throw new LogicException('Your configuration files are not serializable.', 0, $e);
        }
        $this->info('Configuration cached successfully!');
    }
    /**
     * Boot a fresh copy of the application configuration.
     *
     * @return array
     */
    protected function getFreshConfiguration()
    {
        $app = (require $this->laravel->bootstrapPath() . '/app.php');
        $app->useStoragePath($this->laravel->storagePath());
        $app->make(ConsoleKernelContract::class)->bootstrap();
        return $app['config']->all();
    }
}
