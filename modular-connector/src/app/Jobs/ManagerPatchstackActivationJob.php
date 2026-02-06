<?php

namespace Modular\Connector\Jobs;

use Modular\Connector\Events\ManagerItemsActivated;
use Modular\ConnectorDependencies\Illuminate\Bus\Queueable;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldQueue;
use Modular\ConnectorDependencies\Illuminate\Foundation\Bus\Dispatchable;
use Modular\ConnectorDependencies\Illuminate\Support\InteractsWithTime;
use function Modular\ConnectorDependencies\data_get;
use function Modular\ConnectorDependencies\data_set;
use function Modular\ConnectorDependencies\event;

class ManagerPatchstackActivationJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable;
    use Queueable;
    use InteractsWithTime;

    /**
     * @var string
     */
    protected string $mrid;

    /**
     * @var mixed
     */
    protected string $licenseKey;

    /**
     * @var string
     */
    protected $result;

    /**
     * @param string $licenseKey
     * @param string $mrid
     * @param $result
     */
    public function __construct(string $licenseKey, $mrid, $result)
    {
        $this->licenseKey = $licenseKey;
        $this->mrid = $mrid;
        $this->result = $result;
    }

    public function handle(): void
    {
        if (!class_exists(\Patchstack::class)) {
            return;
        }

        $license = explode('-', $this->licenseKey);

        $clientId = $license[1] ?? null;
        $clientSecret = $license[0] ?? null;

        $patchstack = \Patchstack::get_instance();
        $response = $patchstack->activation->alter_license($clientId, $clientSecret, 'activate');

        $success = data_get($response, 'result') === 'success';

        // Force update license status in Patchstack.
        if ($success) {
            $patchstack->api->update_license_status();
        }

        data_set($this->result, '0.success', $success, false);

        event(new ManagerItemsActivated($this->mrid, $this->result));
    }

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return $this->mrid;
    }
}
