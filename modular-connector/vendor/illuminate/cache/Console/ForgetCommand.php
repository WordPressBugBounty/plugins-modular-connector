<?php

namespace Modular\ConnectorDependencies\Illuminate\Cache\Console;

use Modular\ConnectorDependencies\Illuminate\Cache\CacheManager;
use Modular\ConnectorDependencies\Illuminate\Console\Command;
class ForgetCommand extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'cache:forget {key : The key to remove} {store? : The store to remove the key from}';
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove an item from the cache';
    /**
     * The cache manager instance.
     *
     * @var \Illuminate\Cache\CacheManager
     */
    protected $cache;
    /**
     * Create a new cache clear command instance.
     *
     * @param  \Illuminate\Cache\CacheManager  $cache
     * @return void
     */
    public function __construct(CacheManager $cache)
    {
        parent::__construct();
        $this->cache = $cache;
    }
    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $this->cache->store($this->argument('store'))->forget($this->argument('key'));
        $this->info('The [' . $this->argument('key') . '] key has been removed from the cache.');
    }
}
