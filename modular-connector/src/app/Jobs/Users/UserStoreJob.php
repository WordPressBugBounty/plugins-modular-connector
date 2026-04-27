<?php

namespace Modular\Connector\Jobs\Users;

use Modular\Connector\Concerns\FormatsErrors;
use Modular\Connector\Events\ManagerUserCreated;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Database\Models\User;
use Modular\ConnectorDependencies\Illuminate\Bus\Queueable;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldQueue;
use Modular\ConnectorDependencies\Illuminate\Foundation\Bus\Dispatchable;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use function Modular\ConnectorDependencies\data_get;
use function Modular\ConnectorDependencies\event;

class UserStoreJob implements ShouldQueue
{
    use Dispatchable;
    use FormatsErrors;
    use Queueable;

    /**
     * @var string
     */
    protected string $mrid;

    /**
     * @var mixed
     */
    protected $payload;

    /**
     * @param string $mrid
     * @param $payload
     */
    public function __construct(string $mrid, $payload)
    {
        $this->mrid = $mrid;
        $this->payload = $payload;
    }

    public function handle(): void
    {
        try {
            $userdata = array_filter([
                'user_login' => data_get($this->payload, 'username'),
                'user_email' => data_get($this->payload, 'email'),
                'user_pass' => data_get($this->payload, 'password'),
                'first_name' => data_get($this->payload, 'first_name'),
                'last_name' => data_get($this->payload, 'last_name'),
                'display_name' => data_get($this->payload, 'display_name'),
                'role' => data_get($this->payload, 'role'),
            ], fn($value) => $value !== null);

            $userId = wp_insert_user($userdata);

            if (is_wp_error($userId)) {
                throw new \RuntimeException($userId->get_error_message(), (int)$userId->get_error_code());
            }

            $user = User::find($userId);

            event(new ManagerUserCreated($this->mrid, [
                'success' => true,
                'result' => $user->toArray(),
            ]));
        } catch (\Throwable $e) {
            Log::error($e);

            event(new ManagerUserCreated($this->mrid, [
                'success' => false,
                'error' => $this->formatError($e),
            ]));
        }
    }

}
