<?php

namespace Modular\ConnectorDependencies\Illuminate\Database\PDO;

use Modular\ConnectorDependencies\Doctrine\DBAL\Driver\AbstractSQLiteDriver;
use Modular\ConnectorDependencies\Illuminate\Database\PDO\Concerns\ConnectsToDatabase;
class SQLiteDriver extends AbstractSQLiteDriver
{
    use ConnectsToDatabase;
}
