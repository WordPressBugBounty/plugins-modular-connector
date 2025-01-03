<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Modular\ConnectorDependencies\Symfony\Component\Routing\Matcher;

use Modular\ConnectorDependencies\Symfony\Component\ExpressionLanguage\ExpressionFunction;
use Modular\ConnectorDependencies\Symfony\Component\ExpressionLanguage\ExpressionFunctionProviderInterface;
use Modular\ConnectorDependencies\Symfony\Contracts\Service\ServiceProviderInterface;
/**
 * Exposes functions defined in the request context to route conditions.
 *
 * @author Ahmed TAILOULOUTE <ahmed.tailouloute@gmail.com>
 */
class ExpressionLanguageProvider implements ExpressionFunctionProviderInterface
{
    private $functions;
    public function __construct(ServiceProviderInterface $functions)
    {
        $this->functions = $functions;
    }
    /**
     * {@inheritdoc}
     */
    public function getFunctions()
    {
        $functions = [];
        foreach ($this->functions->getProvidedServices() as $function => $type) {
            $functions[] = new ExpressionFunction($function, static function (...$args) use ($function) {
                return sprintf('($context->getParameter(\'_functions\')->get(%s)(%s))', var_export($function, \true), implode(', ', $args));
            }, function ($values, ...$args) use ($function) {
                return $values['context']->getParameter('_functions')->get($function)(...$args);
            });
        }
        return $functions;
    }
    public function get(string $function): callable
    {
        return $this->functions->get($function);
    }
}
