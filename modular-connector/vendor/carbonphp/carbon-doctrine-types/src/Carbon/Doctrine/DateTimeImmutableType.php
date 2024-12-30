<?php

namespace Modular\ConnectorDependencies\Carbon\Doctrine;

use Modular\ConnectorDependencies\Carbon\CarbonImmutable;
use Modular\ConnectorDependencies\Doctrine\DBAL\Types\VarDateTimeImmutableType;
class DateTimeImmutableType extends VarDateTimeImmutableType implements CarbonDoctrineType
{
    /** @use CarbonTypeConverter<CarbonImmutable> */
    use CarbonTypeConverter;
    /**
     * @return class-string<CarbonImmutable>
     */
    protected function getCarbonClassName(): string
    {
        return CarbonImmutable::class;
    }
}
