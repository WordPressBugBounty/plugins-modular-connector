<?php

namespace Modular\Connector\Jobs;

use Modular\Connector\Events\ManagerItemsInstalled;
use Modular\Connector\Facades\Manager;
use Modular\Connector\Services\Manager\AbstractManager;
use Modular\ConnectorDependencies\Illuminate\Bus\Queueable;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldQueue;
use Modular\ConnectorDependencies\Illuminate\Foundation\Bus\Dispatchable;
use function Modular\ConnectorDependencies\data_get;
use function Modular\ConnectorDependencies\dispatch;
use function Modular\ConnectorDependencies\event;

class ManagerInstallJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    /**
     * @var string
     */
    protected string $mrid;

    /**
     * @var array{type: string|null, name: string|null, download_link: string|null, overwrite: bool, activate: bool, clean_cache: bool, patchstack_license_key: string|null}
     */
    protected array $payload;

    /**
     * The number of seconds after which the job's unique lock will be released.
     *
     * @var int
     */
    public $uniqueFor = 2 * 3600; // 2 hour

    /**
     * @param string $mrid
     * @param array{type: string|null, name: string|null, download_link: string|null, overwrite: bool, activate: bool, clean_cache: bool, patchstack_license_key: string|null} $payload
     */
    public function __construct(string $mrid, array $payload)
    {
        $this->mrid = $mrid;
        $this->payload = $payload;
    }

    public function handle(): void
    {
        $type = data_get($this->payload, 'type');
        $downloadLink = data_get($this->payload, 'download_link');
        $overwrite = (bool)data_get($this->payload, 'overwrite', true);

        if ($type === AbstractManager::THEME) {
            $result = Manager::driver(AbstractManager::THEME)->install($downloadLink, $overwrite);
        } else {
            $result = Manager::driver(AbstractManager::PLUGIN)->install($downloadLink, $overwrite);
        }

        $result['name'] = data_get($this->payload, 'name', 'unknown');

        if (data_get($this->payload, 'activate') && $result['success'] === true) {
            $items = (object)[
                $result['item']['basename'] => (object)[
                    'network_wide' => false,
                    'silent' => false,
                ],
            ];

            $normalizedPayload = [
                'type' => $type,
                'items' => $items,
                'extra' => [
                    'clean_cache' => (bool)data_get($this->payload, 'clean_cache', false),
                    'patchstackLicenseKey' => data_get($this->payload, 'patchstack_license_key'),
                ],
            ];

            dispatch(new ManagerManageItemJob($this->mrid, $normalizedPayload, 'activate'));
        }

        event(new ManagerItemsInstalled($this->mrid, $result));
    }

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return $this->mrid;
    }
}
