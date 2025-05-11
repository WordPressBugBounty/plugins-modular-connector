<?php

namespace Modular\Connector\Optimizer\Interfaces;

interface OptimizationInterface
{
    public function all();

    public function optimize(): array;
}
