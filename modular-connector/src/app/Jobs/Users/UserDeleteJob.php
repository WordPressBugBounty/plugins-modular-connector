<?php

namespace Modular\Connector\Jobs\Users;

use Modular\Connector\Concerns\FormatsErrors;
use Modular\Connector\Events\ManagerUserDeleted;
use Modular\ConnectorDependencies\Illuminate\Bus\Queueable;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldQueue;
use Modular\ConnectorDependencies\Illuminate\Foundation\Bus\Dispatchable;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use function Modular\ConnectorDependencies\data_get;
use function Modular\ConnectorDependencies\event;

class UserDeleteJob implements ShouldQueue
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
            $users = data_get($this->payload, 'users', []);

            if (empty($users)) {
                throw new \RuntimeException('Users array is required');
            }

            require_once ABSPATH . 'wp-admin/includes/user.php';

            $deleted = [];
            $errors = [];

            foreach ($users as $entry) {
                $userId = data_get($entry, 'id');
                $reassign = data_get($entry, 'assign_posts_to');

                $result = wp_delete_user($userId, $reassign);

                if ($result === false) {
                    $errors[] = $userId;
                } else {
                    $deleted[] = $userId;
                }
            }

            event(new ManagerUserDeleted($this->mrid, [
                'success' => empty($errors),
                'result' => [
                    'deleted' => $deleted,
                    'errors' => $errors,
                ],
            ]));
        } catch (\Throwable $e) {
            Log::error($e);

            event(new ManagerUserDeleted($this->mrid, [
                'success' => false,
                'error' => $this->formatError($e),
            ]));
        }
    }

}
