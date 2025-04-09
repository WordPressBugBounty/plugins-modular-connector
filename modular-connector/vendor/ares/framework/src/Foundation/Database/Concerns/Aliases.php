<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Database\Concerns;

use Modular\ConnectorDependencies\Illuminate\Support\Arr;
trait Aliases
{
    /**
     * @param $new
     * @param $old
     * @return void
     */
    public static function addAlias($new, $old)
    {
        static::$aliases[$new] = $old;
    }
    /**
     * @param $key
     * @param $value
     * @return array|\ArrayAccess|mixed|null
     */
    public function mutateAttribute($key, $value)
    {
        if ($this->hasGetMutator($key)) {
            return parent::mutateAttribute($key, $value);
        }
        return $this->getAttribute($key);
    }
    /**
     * @param $key
     * @return array|\ArrayAccess|mixed|null
     */
    public function getAttribute($key)
    {
        $value = parent::getAttribute($key);
        if ($value === null && count(static::getAliases())) {
            if ($value = Arr::get(static::getAliases(), $key)) {
                if (is_array($value)) {
                    $meta = Arr::get($value, 'meta');
                    return $meta ? $this->meta->{$meta} : null;
                }
                return parent::getAttribute($value);
            }
        }
        return $value;
    }
    /**
     * @return array
     */
    public static function getAliases()
    {
        if (isset(parent::$aliases) && count(parent::$aliases)) {
            return array_merge(parent::$aliases, static::$aliases);
        }
        return static::$aliases;
    }
}
