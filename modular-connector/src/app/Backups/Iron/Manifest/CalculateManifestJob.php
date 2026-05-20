<?php

namespace Modular\Connector\Backups\Iron\Manifest;

use Modular\Connector\Manifest\Contracts\ManifestablePartContract;
use Modular\ConnectorDependencies\Illuminate\Bus\Queueable;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldQueue;
use Modular\ConnectorDependencies\Illuminate\Foundation\Bus\Dispatchable;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;

class CalculateManifestJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    /**
     * @var ManifestablePartContract
     */
    protected $part;

    /**
     * @param ManifestablePartContract $part
     */
    public function __construct(ManifestablePartContract $part)
    {
        $this->part = $part;
        $this->queue = 'backups';
    }

    /**
     * @return void
     */
    public function handle()
    {
        if ($this->part->isCancelled()) {
            return;
        }

        try {
            $this->part->createManifest()->calculate();
        } catch (\Throwable $e) {
            Log::error($e);

            $this->part->markAsFailed($this->part->getManifestFailedStatus(), $e);
        }
    }
}
