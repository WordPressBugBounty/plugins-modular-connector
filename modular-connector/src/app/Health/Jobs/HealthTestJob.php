<?php

namespace Modular\Connector\Health\Jobs;

use Modular\Connector\Events\ManagerHealthUpdated;
use Modular\Connector\Health\Contracts\HealthTestContract;
use Modular\ConnectorDependencies\Illuminate\Bus\Queueable;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldQueue;
use Modular\ConnectorDependencies\Illuminate\Foundation\Bus\Dispatchable;
use function Modular\ConnectorDependencies\event;

class HealthTestJob implements ShouldQueue
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
    protected string $testClass;

    /**
     * @param string $mrid
     * @param string $testClass
     */
    public function __construct(string $mrid, string $testClass)
    {
        $this->mrid = $mrid;
        $this->testClass = $testClass;
        $this->queue = 'backups';
    }

    /**
     * @return void
     */
    public function handle()
    {
        $test = null;

        try {
            /** @var HealthTestContract $test */
            $test = new $this->testClass();
            $result = $test->run();
        } catch (\Throwable $e) {
            $result = [
                'status' => 'critical',
                'data' => [
                    'error' => $e->getMessage(),
                ],
            ];
        }

        $identifier = $test ? $test->identifier() : $this->testClass;

        event(new ManagerHealthUpdated($this->mrid, [
            $identifier => $result,
        ]));
    }
}
