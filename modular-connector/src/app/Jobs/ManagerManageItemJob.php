<?php

namespace Modular\Connector\Jobs;

use Modular\Connector\Cache\Jobs\CacheClearJob;
use Modular\Connector\Concerns\NormalizesManagerPayload;
use Modular\Connector\Events\ManagerItemsActivated;
use Modular\Connector\Events\ManagerItemsDeactivated;
use Modular\Connector\Events\ManagerItemsDeleted;
use Modular\Connector\Events\ManagerItemsUpgraded;
use Modular\Connector\Facades\Manager;
use Modular\Connector\Facades\Server;
use Modular\Connector\Services\Manager\AbstractManager;
use Modular\ConnectorDependencies\Ares\Framework\Foundation\Http\HttpUtils;
use Modular\ConnectorDependencies\Illuminate\Bus\Queueable;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldQueue;
use Modular\ConnectorDependencies\Illuminate\Foundation\Bus\Dispatchable;
use Modular\ConnectorDependencies\Illuminate\Support\Collection;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use Modular\ConnectorDependencies\Illuminate\Support\InteractsWithTime;
use function Modular\ConnectorDependencies\app;
use function Modular\ConnectorDependencies\data_get;
use function Modular\ConnectorDependencies\dispatch;
use function Modular\ConnectorDependencies\event;

class ManagerManageItemJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable;
    use Queueable;
    use InteractsWithTime;
    use NormalizesManagerPayload;

    /**
     * @var string
     */
    protected string $mrid;

    /**
     * @var array{type: string|null, items: mixed, extra: array}
     */
    protected array $payload;

    /**
     * @var string
     */
    protected string $action;

    /**
     * @var int
     */
    protected int $tries;

    /**
     * The number of seconds after which the job's unique lock will be released.
     *
     * @var int
     */
    public $uniqueFor = 2 * 3600; // 2 hour

    /**
     * Actions that are not supported for specific types.
     */
    private const BLOCKED_ACTIONS = [
        AbstractManager::THEME => ['deactivate'],
        AbstractManager::CORE => ['activate', 'deactivate', 'delete'],
        AbstractManager::TRANSLATION => ['activate', 'deactivate', 'delete'],
    ];

    /**
     * Action-to-event class mapping.
     */
    private const ACTION_EVENTS = [
        'activate' => ManagerItemsActivated::class,
        'deactivate' => ManagerItemsDeactivated::class,
        'delete' => ManagerItemsDeleted::class,
        'upgrade' => ManagerItemsUpgraded::class,
    ];

    /**
     * Accepts any payload shape (normalized array, legacy stdClass, or array with legacy
     * `plugins`/`themes` keys) for backward compatibility with callers from older plugin
     * versions and queued jobs serialized before 2.8.0. Normalizes via the trait.
     *
     * @param string $mrid
     * @param mixed $payload
     * @param string $action
     * @param int $tries
     */
    public function __construct(string $mrid, $payload, string $action, int $tries = 1)
    {
        $this->mrid = $mrid;
        $this->payload = $this->normalizeManagePayload($payload);
        $this->action = $action;
        $this->tries = $tries;
    }

    public function handle(): void
    {
        $type = $this->payload['type'];
        $items = $this->payload['items'];
        $extra = $this->payload['extra'];
        $action = $this->action;

        if (!$this->isActionAllowed($type, $action)) {
            return;
        }

        $result = $this->executeAction($type, $action, $items);
        $result = $this->normalizeResult($type, $result);

        if ($this->shouldRetryUpgrade($action, $result)) {
            $this->dispatchRetry();
            return;
        }

        $this->dispatchEvent($action, $type, $extra, $result);
    }

    /**
     * Checks whether the given action is allowed for the type.
     *
     * @param string|null $type
     * @param string $action
     * @return bool
     */
    private function isActionAllowed(?string $type, string $action): bool
    {
        if (empty($type)) {
            return false;
        }

        $blocked = self::BLOCKED_ACTIONS[$type] ?? [];

        return !in_array($action, $blocked);
    }

    /**
     * Runs the action on the manager driver.
     *
     * @param string $type
     * @param string $action
     * @param mixed $items
     * @return mixed
     */
    private function executeAction(string $type, string $action, $items)
    {
        $facade = Manager::driver($type);

        try {
            if (in_array($type, [AbstractManager::CORE, AbstractManager::TRANSLATION])) {
                return $facade->{$action}();
            }

            return $facade->{$action}($items);
        } finally {
            Server::maintenanceMode(false);
        }
    }

    /**
     * Determines if an upgrade should be retried due to file permission errors.
     *
     * @param string $action
     * @param array $result
     * @return bool
     */
    private function shouldRetryUpgrade(string $action, array $result): bool
    {
        if ($action !== 'upgrade' || HttpUtils::isCron() || $this->tries !== 1) {
            return false;
        }

        return Collection::make($result)
            ->some(fn($item) => !boolval(data_get($item, 'success')) && data_get($item, 'response.error.code') === 'copy_failed_pclzip');
    }

    /**
     * Dispatches a retry of this job with incremented tries.
     */
    private function dispatchRetry(): void
    {
        Log::debug('Retrying the job because of file permissions.', [
            'mrid' => $this->mrid,
            'action' => $this->action,
            'payload' => $this->payload,
        ]);

        dispatch(new ManagerManageItemJob($this->mrid, $this->payload, $this->action, $this->tries + 1));

        app()->dontDispatchScheduleRun();

        HttpUtils::restartQueue($this->currentTime());
    }

    /**
     * Ensures the result is always a flat array of items.
     *
     * @param string $type
     * @param mixed $result
     * @return array
     */
    private function normalizeResult(string $type, $result): array
    {
        // Core/translation drivers return a single result; wrap in array for consistency
        if (in_array($type, [AbstractManager::CORE, AbstractManager::TRANSLATION])) {
            return [$result];
        }

        return $result;
    }

    /**
     * Dispatches the appropriate post-action event, deferring it when
     * a post-action integration or cache clearing is requested.
     *
     * @param string $action
     * @param string $type
     * @param array $extra
     * @param array $result
     */
    private function dispatchEvent(string $action, string $type, array $extra, array $result): void
    {
        if ($type === AbstractManager::TRANSLATION) {
            return;
        }

        $eventClass = self::ACTION_EVENTS[$action] ?? null;

        if (!$eventClass) {
            return;
        }

        // Patchstack activation: defer event to PatchstackJob
        if ($this->shouldDeferToPatchstack($action, $extra, $result)) {
            HttpUtils::restartQueue($this->currentTime());

            dispatch(new ManagerPatchstackActivationJob(
                data_get($extra, 'patchstackLicenseKey'),
                $this->mrid,
                $result
            ));

            return;
        }

        // Cache clearing: defer event to after caches are purged
        if (data_get($extra, 'clean_cache', false)) {
            dispatch(new CacheClearJob($eventClass, $this->mrid, $result));

            return;
        }

        event(new $eventClass($this->mrid, $result));
    }

    /**
     * Whether the Patchstack integration should handle the event dispatch.
     *
     * @param string $action
     * @param array $extra
     * @param array $result
     * @return bool
     */
    private function shouldDeferToPatchstack(string $action, array $extra, array $result): bool
    {
        return $action === 'activate'
            && !empty(data_get($extra, 'patchstackLicenseKey'))
            && data_get($result, '0.success', false);
    }

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return $this->mrid;
    }
}
