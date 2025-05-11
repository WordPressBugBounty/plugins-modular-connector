<?php

namespace Modular\Connector\Models\Concerns;

use Modular\ConnectorDependencies\Illuminate\Support\Arr;

trait Aliases
{
    public static function getAliases()
    {
        if (isset(parent::$aliases) && count(parent::$aliases)) {
            return array_merge(parent::$aliases, static::$aliases);
        }

        return static::$aliases;
    }

    public static function addAlias($new, $old)
    {
        static::$aliases[$new] = $old;
    }

    public function getAttribute($key)
    {
        $value = parent::getAttribute($key);

        if ($value === null && count(static::getAliases())) {
            if ($value = Arr::get(static::getAliases(), $key)) {
                if (is_array($value)) {
                    $meta = Arr::get($value, 'meta');

                    return $meta ? $this->meta->$meta : null;
                }

                return parent::getAttribute($value);
            }
        }

        return $value;
    }

    public function mutateAttribute($key, $value)
    {
        if ($this->hasGetMutator($key)) {
            return parent::mutateAttribute($key, $value);
        }

        return $this->getAttribute($key);
    }
}
