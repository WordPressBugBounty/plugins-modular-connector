<?php

/**
 * This file is part of the Carbon package.
 *
 * (c) Brian Nesbitt <brian@nesbot.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Modular\ConnectorDependencies\Carbon;

use ReflectionMethod;
use Modular\ConnectorDependencies\Symfony\Component\Translation;
use Modular\ConnectorDependencies\Symfony\Contracts\Translation\TranslatorInterface;
$transMethod = new ReflectionMethod(class_exists(TranslatorInterface::class) ? TranslatorInterface::class : Translation\Translator::class, 'trans');
require $transMethod->hasReturnType() ? __DIR__ . '/../../lazy/Carbon/TranslatorStrongType.php' : __DIR__ . '/../../lazy/Carbon/TranslatorWeakType.php';
class Translator extends LazyTranslator
{
    // Proxy dynamically loaded LazyTranslator in a static way
}
