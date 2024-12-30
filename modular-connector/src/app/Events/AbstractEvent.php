<?php

namespace Modular\Connector\Events;

use Modular\ConnectorDependencies\Illuminate\Foundation\Events\Dispatchable;
use Modular\ConnectorDependencies\Illuminate\Queue\SerializesModels;

abstract class AbstractEvent
{
    use Dispatchable;
    use SerializesModels;

    /**
     * Request ID from Modular
     *
     * @var string
     */
    public string $mrid;

    /**
     * @var
     */
    public $payload;

    /***
     * @param string $mrid
     * @param $payload
     */
    public function __construct(string $mrid, $payload)
    {
        $this->mrid = $mrid;
        $this->payload = $payload;
    }
}
