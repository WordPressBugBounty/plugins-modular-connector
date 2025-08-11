<?php

namespace Modular\Connector\Jobs;

use Modular\Connector\Facades\Manager;
use Modular\Connector\Services\Manager\ManagerDatabase;
use Modular\ConnectorDependencies\Illuminate\Bus\Queueable;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldQueue;
use Modular\ConnectorDependencies\Illuminate\Foundation\Bus\Dispatchable;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;

class ManagerUpgradeDatabaseJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable;
    use Queueable;

    /**
     * @var string
     */
    protected string $mrid;

    /**
     * @var string
     */
    protected $basename;

    /**
     * @var string
     */
    protected string $action;

    /**
     * The number of seconds after which the job's unique lock will be released.
     *
     * @var int
     */
    public $uniqueFor = 2 * 3600; // 2 hour

    /**
     * @param string $mrid
     * @param string $basename
     * @param string $action
     */
    public function __construct(string $mrid, string $basename, string $action)
    {
        $this->mrid = $mrid;
        $this->basename = $basename;
        $this->action = $action;
    }

    public function handle(): void
    {
        $basename = $this->basename;

        /**
         * @var ManagerDatabase $manager
         */
        $manager = Manager::driver('database');

        try {
            if ($basename === 'woocommerce/woocommerce.php') {
                $manager->upgradeWooCommerce();
            } elseif ($basename === 'elementor/elementor.php') {
                $manager->upgradeElementor(false);
            } elseif ($basename === 'elementor-pro/elementor-pro.php') {
                $manager->upgradeElementor(true);
            } elseif ($basename === 'core') {
                $manager->upgrade();
            }
        } catch (\Throwable $e) {
            Log::error($e, [
                'mrid' => $this->mrid,
                'basename' => $basename,
                'action' => $this->action,
            ]);

            return;
        }
    }

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return $this->mrid;
    }
}
