<?php

namespace Modular\Connector\Optimizer;

use Modular\Connector\Concerns\FormatsErrors;
use Modular\Connector\Optimizer\Interfaces\OptimizationInterface;

abstract class Optimization implements OptimizationInterface
{
    use FormatsErrors;
    /**
     * Total weeks to retain data
     *
     * @var int
     */
    protected int $retainedInterval = 0;

    /**
     * Total number of revisions to retain
     *
     * @var int
     */
    protected int $retainedPostRevisions = 3;

    /**
     * Remove only expired data
     *
     * @var bool
     */
    protected bool $removeOnlyExpired = true;

    /**
     * Optimization constructor.
     *
     * @param array $options
     */
    public function __construct($options)
    {
        foreach ($options as $option => $value) {
            if (isset($this->{$option})) {
                $this->{$option} = $value;
            }
        }
    }

}
