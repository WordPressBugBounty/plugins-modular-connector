<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Modular\ConnectorDependencies\Symfony\Component\EventDispatcher\DependencyInjection;

use Modular\ConnectorDependencies\Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Modular\ConnectorDependencies\Symfony\Component\DependencyInjection\ContainerBuilder;
/**
 * This pass allows bundles to extend the list of event aliases.
 *
 * @author Alexander M. Turek <me@derrabus.de>
 */
class AddEventAliasesPass implements CompilerPassInterface
{
    private $eventAliases;
    private $eventAliasesParameter;
    public function __construct(array $eventAliases, string $eventAliasesParameter = 'event_dispatcher.event_aliases')
    {
        if (1 < \func_num_args()) {
            \Modular\ConnectorDependencies\trigger_deprecation('symfony/event-dispatcher', '5.3', 'Configuring "%s" is deprecated.', __CLASS__);
        }
        $this->eventAliases = $eventAliases;
        $this->eventAliasesParameter = $eventAliasesParameter;
    }
    public function process(ContainerBuilder $container): void
    {
        $eventAliases = $container->hasParameter($this->eventAliasesParameter) ? $container->getParameter($this->eventAliasesParameter) : [];
        $container->setParameter($this->eventAliasesParameter, array_merge($eventAliases, $this->eventAliases));
    }
}
