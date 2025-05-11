<?php

namespace Modular\Connector\Models\Concerns;

trait OrderScopes
{
    public function scopeNewest($query)
    {
        return $query->orderBy(static::CREATED_AT, 'desc');
    }

    public function scopeOldest($query)
    {
        return $query->orderBy(static::CREATED_AT, 'asc');
    }
}
