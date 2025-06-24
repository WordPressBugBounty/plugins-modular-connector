<?php

namespace Modular\Connector\Models\Meta;

use Exception;
use Modular\Connector\Models\Collection\MetaCollection;
use Modular\ConnectorDependencies\Illuminate\Database\Eloquent\Model;

abstract class Meta extends Model
{
    protected $primaryKey = 'meta_id';

    public $timestamps = false;

    protected $appends = ['value'];

    public function getValueAttribute()
    {
        try {
            $value = unserialize($this->meta_value);

            return $value === false && $this->meta_value !== false ?
                $this->meta_value :
                $value;
        }
        catch(Exception $e) {
            
        }
    }

    public function newCollection(array $models = [])
    {
        return new MetaCollection($models);
    }
}
