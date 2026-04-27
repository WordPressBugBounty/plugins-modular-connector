<?php

namespace Modular\Connector\Jobs\Users;

use Modular\Connector\Concerns\FormatsErrors;
use Modular\Connector\Events\ManagerUserUpdated;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Database\Models\User;
use Modular\ConnectorDependencies\Illuminate\Bus\Queueable;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldQueue;
use Modular\ConnectorDependencies\Illuminate\Foundation\Bus\Dispatchable;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use function Modular\ConnectorDependencies\data_get;
use function Modular\ConnectorDependencies\event;

class UserUpdateJob implements ShouldQueue
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
            $userId = data_get($this->payload, 'id');

            if (empty($userId)) {
                throw new \RuntimeException('User ID is required');
            }

            $userdata = array_filter([
                'ID' => $userId,
                'user_email' => data_get($this->payload, 'email'),
                'user_pass' => data_get($this->payload, 'password'),
                'first_name' => data_get($this->payload, 'first_name'),
                'last_name' => data_get($this->payload, 'last_name'),
                'display_name' => data_get($this->payload, 'display_name'),
                'role' => data_get($this->payload, 'role'),
            ], fn($value) => $value !== null);

            $result = wp_update_user($userdata);

            if (is_wp_error($result)) {
                throw new \RuntimeException($result->get_error_message(), (int)$result->get_error_code());
            }

            $user = User::find($result);

            event(new ManagerUserUpdated($this->mrid, [
                'success' => true,
                'result' => $user->toArray(),
            ]));
        } catch (\Throwable $e) {
            Log::error($e);

            event(new ManagerUserUpdated($this->mrid, [
                'success' => false,
                'error' => $this->formatError($e),
            ]));
        }
    }

}
