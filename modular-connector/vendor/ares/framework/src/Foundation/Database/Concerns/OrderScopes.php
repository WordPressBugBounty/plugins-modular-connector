<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Database\Concerns;

trait OrderScopes
{
    /**
     * @param $query
     * @return mixed
     */
    public function scopeNewest($query)
    {
        return $query->orderBy(static::CREATED_AT, 'desc');
    }
    /**
     * @param $query
     * @return mixed
     */
    public function scopeOldest($query)
    {
        return $query->orderBy(static::CREATED_AT, 'asc');
    }
}
