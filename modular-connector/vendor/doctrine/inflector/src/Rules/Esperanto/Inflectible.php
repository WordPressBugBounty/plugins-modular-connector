<?php

declare (strict_types=1);
namespace Modular\ConnectorDependencies\Doctrine\Inflector\Rules\Esperanto;

use Modular\ConnectorDependencies\Doctrine\Inflector\Rules\Pattern;
use Modular\ConnectorDependencies\Doctrine\Inflector\Rules\Substitution;
use Modular\ConnectorDependencies\Doctrine\Inflector\Rules\Transformation;
use Modular\ConnectorDependencies\Doctrine\Inflector\Rules\Word;
class Inflectible
{
    /** @return Transformation[] */
    public static function getSingular(): iterable
    {
        yield new Transformation(new Pattern('oj$'), 'o');
    }
    /** @return Transformation[] */
    public static function getPlural(): iterable
    {
        yield new Transformation(new Pattern('o$'), 'oj');
    }
    /** @return Substitution[] */
    public static function getIrregular(): iterable
    {
        yield new Substitution(new Word(''), new Word(''));
    }
}
