<?php

namespace Modular\ConnectorDependencies\Cron;

interface FieldFactoryInterface
{
    public function getField(int $position): FieldInterface;
}
