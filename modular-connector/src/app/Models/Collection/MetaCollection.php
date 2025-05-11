<?php

namespace Modular\Connector\Models\Collection;

use Modular\ConnectorDependencies\Illuminate\Database\Eloquent\Collection;

class MetaCollection extends Collection
{
    public function __get($key)
    {
        if (in_array($key, static::$proxies)) {
            return parent::__get($key);
        }

        if (isset($this->items) && count($this->items)) {
            $meta = $this->first(function ($meta) use ($key) {
                return $meta->meta_key === $key;
            });

            return $meta ? $meta->meta_value : null;
        }

        return null;
    }

    public function __isset($name)
    {
        return !is_null($this->__get($name));
    }
}
