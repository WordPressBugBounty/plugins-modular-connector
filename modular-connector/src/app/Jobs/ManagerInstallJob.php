<?php

namespace Modular\Connector\Jobs;

use Modular\Connector\Events\ManagerItemsInstalled;
use Modular\Connector\Facades\Plugin;
use Modular\Connector\Facades\Theme;

class ManagerInstallJob extends AbstractJob
{
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

    public function handle()
    {
        $payload = $this->payload;

        if ($payload->type === 'theme') {
            $result = Theme::install($payload->downloadLink, $payload->overwrite);
        } else {
            $result = Plugin::install($payload->downloadLink, $payload->overwrite);
        }

        $result['name'] = $payload->name ?? 'unknown';

        ManagerItemsInstalled::dispatch($this->mrid, $result);

        if ($payload->activate && $result['success'] === true) {
            $key = $payload->type . 's';

            $payload = (object)[
                $key => (object) [$result['item']['basename'] => (object)[
                    'network_wide' => false,
                    'silent' => true
                ]],
            ];

            ManagerManageItemJob::dispatch($this->mrid, $payload, 'activate');
        }

        return $result;
    }
}
