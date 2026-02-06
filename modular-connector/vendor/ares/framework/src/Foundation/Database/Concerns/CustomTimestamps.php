<?php

namespace Modular\ConnectorDependencies\Ares\Framework\Foundation\Database\Concerns;

trait CustomTimestamps
{
    /**
     * Set the created_at timestamp and also update the corresponding GMT field
     *
     * @param $value
     * @return mixed
     */
    public function setCreatedAt($value)
    {
        $gmt_field = static::CREATED_AT . '_gmt';
        $this->{$gmt_field} = $value;
        return parent::setCreatedAt($value);
    }
    /**
     * Set the updated_at timestamp and also update the corresponding GMT field
     *
     * @param $value
     * @return mixed
     */
    public function setUpdatedAt($value)
    {
        $gmt_field = static::UPDATED_AT . '_gmt';
        $this->{$gmt_field} = $value;
        return parent::setUpdatedAt($value);
    }
}
