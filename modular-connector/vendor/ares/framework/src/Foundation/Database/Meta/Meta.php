<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Database\Meta;

use Modular\ConnectorDependencies\Ares\Framework\Foundation\Database\Collection\MetaCollection;
use Modular\ConnectorDependencies\Illuminate\Database\Eloquent\Model;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
abstract class Meta extends Model
{
    /**
     * @var bool
     */
    public $timestamps = \false;
    /**
     * @var string
     */
    protected $primaryKey = 'meta_id';
    /**
     * @var string[]
     */
    protected $appends = ['value'];
    /**
     * @return mixed|void
     */
    public function getValueAttribute()
    {
        try {
            $value = unserialize($this->meta_value);
            return $value === \false && $this->meta_value !== \false ? $this->meta_value : $value;
        } catch (\Throwable $e) {
            Log::error($e);
        }
    }
    /**
     * @param array $models
     * @return MetaCollection
     */
    public function newCollection(array $models = [])
    {
        return new MetaCollection($models);
    }
}
