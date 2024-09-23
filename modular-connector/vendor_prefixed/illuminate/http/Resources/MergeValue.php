<?php

namespace Modular\ConnectorDependencies\Illuminate\Http\Resources;

use Modular\ConnectorDependencies\Illuminate\Support\Collection;
use JsonSerializable;
/** @internal */
class MergeValue
{
    /**
     * The data to be merged.
     *
     * @var array
     */
    public $data;
    /**
     * Create a new merge value instance.
     *
     * @param  \Illuminate\Support\Collection|\JsonSerializable|array  $data
     * @return void
     */
    public function __construct($data)
    {
        if ($data instanceof Collection) {
            $this->data = $data->all();
        } elseif ($data instanceof JsonSerializable) {
            $this->data = $data->jsonSerialize();
        } else {
            $this->data = $data;
        }
    }
}