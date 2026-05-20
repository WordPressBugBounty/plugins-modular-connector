<?php

namespace Modular\Connector\Health\Contracts;

interface HealthTestContract
{
    /**
     * @return string
     */
    public function identifier();

    /**
     * @return array
     */
    public function run();
}
