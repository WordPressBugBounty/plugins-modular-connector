<?php

namespace Modular\Connector\Models\Concerns;

trait CustomTimestamps
{
    public function setCreatedAt($value)
    {
        $gmt_field = static::CREATED_AT . '_gmt';
        $this->{$gmt_field} = $value;

        return parent::setCreatedAt($value);
    }

    public function setUpdatedAt($value)
    {
        $gmt_field = static::UPDATED_AT . '_gmt';
        $this->{$gmt_field} = $value;

        return parent::setUpdatedAt($value);
    }
}
