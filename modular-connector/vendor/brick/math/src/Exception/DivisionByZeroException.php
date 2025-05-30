<?php

declare (strict_types=1);
namespace Modular\ConnectorDependencies\Brick\Math\Exception;

/**
 * Exception thrown when a division by zero occurs.
 */
class DivisionByZeroException extends MathException
{
    /**
     * @return DivisionByZeroException
     *
     * @psalm-pure
     */
    public static function divisionByZero(): DivisionByZeroException
    {
        return new self('Division by zero.');
    }
    /**
     * @return DivisionByZeroException
     *
     * @psalm-pure
     */
    public static function modulusMustNotBeZero(): DivisionByZeroException
    {
        return new self('The modulus must not be zero.');
    }
    /**
     * @return DivisionByZeroException
     *
     * @psalm-pure
     */
    public static function denominatorMustNotBeZero(): DivisionByZeroException
    {
        return new self('The denominator of a rational number cannot be zero.');
    }
}
