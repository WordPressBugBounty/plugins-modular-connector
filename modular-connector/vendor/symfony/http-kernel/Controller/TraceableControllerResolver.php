<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Modular\ConnectorDependencies\Symfony\Component\HttpKernel\Controller;

use Modular\ConnectorDependencies\Symfony\Component\HttpFoundation\Request;
use Modular\ConnectorDependencies\Symfony\Component\Stopwatch\Stopwatch;
/**
 * @author Fabien Potencier <fabien@symfony.com>
 */
class TraceableControllerResolver implements ControllerResolverInterface
{
    private $resolver;
    private $stopwatch;
    public function __construct(ControllerResolverInterface $resolver, Stopwatch $stopwatch)
    {
        $this->resolver = $resolver;
        $this->stopwatch = $stopwatch;
    }
    /**
     * {@inheritdoc}
     */
    public function getController(Request $request)
    {
        $e = $this->stopwatch->start('controller.get_callable');
        try {
            return $this->resolver->getController($request);
        } finally {
            $e->stop();
        }
    }
}
