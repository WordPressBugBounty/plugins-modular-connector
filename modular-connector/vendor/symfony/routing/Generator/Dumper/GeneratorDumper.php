<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Modular\ConnectorDependencies\Symfony\Component\Routing\Generator\Dumper;

use Modular\ConnectorDependencies\Symfony\Component\Routing\RouteCollection;
/**
 * GeneratorDumper is the base class for all built-in generator dumpers.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
abstract class GeneratorDumper implements GeneratorDumperInterface
{
    private $routes;
    public function __construct(RouteCollection $routes)
    {
        $this->routes = $routes;
    }
    /**
     * {@inheritdoc}
     */
    public function getRoutes()
    {
        return $this->routes;
    }
}
