<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Database\Collection;

use Modular\ConnectorDependencies\Illuminate\Database\Eloquent\Collection;
class MetaCollection extends Collection
{
    /**
     * Cached index of meta items by meta_key for O(1) access
     */
    protected $metaIndex = null;
    /**
     * Build or return cached index of meta items by meta_key
     */
    protected function getMetaIndex(): array
    {
        if ($this->metaIndex === null) {
            $this->metaIndex = [];
            foreach ($this->items as $meta) {
                $this->metaIndex[$meta->meta_key] = $meta;
            }
        }
        return $this->metaIndex;
    }
    /**
     * Invalidate cache when items change
     */
    protected function invalidateIndex(): void
    {
        $this->metaIndex = null;
    }
    public function __isset($name)
    {
        return !is_null($this->__get($name));
    }
    public function __get($key)
    {
        if (in_array($key, static::$proxies)) {
            return parent::__get($key);
        }
        if (!empty($this->items)) {
            $index = $this->getMetaIndex();
            return $index[$key]->meta_value ?? null;
        }
        return null;
    }
    /**
     * Override methods that modify items to invalidate cache
     */
    public function push(...$values)
    {
        $this->invalidateIndex();
        return parent::push($values);
    }
    public function put($key, $value)
    {
        $this->invalidateIndex();
        return parent::put($key, $value);
    }
    public function forget($keys)
    {
        $this->invalidateIndex();
        return parent::forget($keys);
    }
}
