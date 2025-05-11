<?php

namespace Modular\Connector\Optimizer;

use Modular\Connector\Optimizer\Interfaces\OptimizationInterface;

abstract class Optimization implements OptimizationInterface
{
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

    /**
     * Receives a WordPress error as $error parameters and returns it in our desired format.
     *
     * @param \WP_Error|mixed $error
     * @return array[]
     * @throws \Exception
     * @depreacted Pending error handler
     */
    final protected function formatError(\Throwable $error)
    {
        return [
            'code' => $error->getCode(),
            'message' => sprintf(
                '%s in %s on line %s',
                $error->getMessage(),
                $error->getFile(),
                $error->getLine()
            ),
        ];
    }
}
