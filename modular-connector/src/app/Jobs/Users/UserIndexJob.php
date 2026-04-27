<?php

namespace Modular\Connector\Jobs\Users;

use Modular\Connector\Concerns\FormatsErrors;
use Modular\Connector\Events\ManagerUserIndexed;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\ServerSetup;
use Modular\ConnectorDependencies\Illuminate\Bus\Queueable;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldQueue;
use Modular\ConnectorDependencies\Illuminate\Foundation\Bus\Dispatchable;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use function Modular\ConnectorDependencies\event;

class UserIndexJob implements ShouldQueue
{
    use Dispatchable;
    use FormatsErrors;
    use Queueable;

    /**
     * @var string
     */
    protected string $mrid;

    /**
     * @param string $mrid
     */
    public function __construct(string $mrid)
    {
        $this->mrid = $mrid;
    }

    public function handle(): void
    {
        try {
            $users = ServerSetup::getAllAdminUsers();

            event(new ManagerUserIndexed($this->mrid, [
                'success' => true,
                'result' => $users->toArray(),
            ]));
        } catch (\Throwable $e) {
            Log::error($e);

            event(new ManagerUserIndexed($this->mrid, [
                'success' => false,
                'error' => $this->formatError($e),
            ]));
        }
    }

}
