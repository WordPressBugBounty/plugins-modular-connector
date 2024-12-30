<?php

namespace Modular\ConnectorDependencies\Carbon\Doctrine;

use Modular\ConnectorDependencies\Carbon\Carbon;
use Modular\ConnectorDependencies\Doctrine\DBAL\Types\VarDateTimeType;
class DateTimeType extends VarDateTimeType implements CarbonDoctrineType
{
    /** @use CarbonTypeConverter<Carbon> */
    use CarbonTypeConverter;
}
