<?php

namespace Modular\ConnectorDependencies\Illuminate\Database;

use Modular\ConnectorDependencies\Doctrine\DBAL\Driver\PDOMySql\Driver as DoctrineDriver;
use Modular\ConnectorDependencies\Doctrine\DBAL\Version;
use Modular\ConnectorDependencies\Illuminate\Database\PDO\MySqlDriver;
use Modular\ConnectorDependencies\Illuminate\Database\Query\Grammars\MySqlGrammar as QueryGrammar;
use Modular\ConnectorDependencies\Illuminate\Database\Query\Processors\MySqlProcessor;
use Modular\ConnectorDependencies\Illuminate\Database\Schema\Grammars\MySqlGrammar as SchemaGrammar;
use Modular\ConnectorDependencies\Illuminate\Database\Schema\MySqlBuilder;
use Modular\ConnectorDependencies\Illuminate\Database\Schema\MySqlSchemaState;
use Modular\ConnectorDependencies\Illuminate\Filesystem\Filesystem;
use PDO;
class MySqlConnection extends Connection
{
    /**
     * Determine if the connected database is a MariaDB database.
     *
     * @return bool
     */
    public function isMaria()
    {
        return strpos($this->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION), 'MariaDB') !== \false;
    }
    /**
     * Get the default query grammar instance.
     *
     * @return \Illuminate\Database\Query\Grammars\MySqlGrammar
     */
    protected function getDefaultQueryGrammar()
    {
        return $this->withTablePrefix(new QueryGrammar());
    }
    /**
     * Get a schema builder instance for the connection.
     *
     * @return \Illuminate\Database\Schema\MySqlBuilder
     */
    public function getSchemaBuilder()
    {
        if (is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }
        return new MySqlBuilder($this);
    }
    /**
     * Get the default schema grammar instance.
     *
     * @return \Illuminate\Database\Schema\Grammars\MySqlGrammar
     */
    protected function getDefaultSchemaGrammar()
    {
        return $this->withTablePrefix(new SchemaGrammar());
    }
    /**
     * Get the schema state for the connection.
     *
     * @param  \Illuminate\Filesystem\Filesystem|null  $files
     * @param  callable|null  $processFactory
     * @return \Illuminate\Database\Schema\MySqlSchemaState
     */
    public function getSchemaState(Filesystem $files = null, callable $processFactory = null)
    {
        return new MySqlSchemaState($this, $files, $processFactory);
    }
    /**
     * Get the default post processor instance.
     *
     * @return \Illuminate\Database\Query\Processors\MySqlProcessor
     */
    protected function getDefaultPostProcessor()
    {
        return new MySqlProcessor();
    }
    /**
     * Get the Doctrine DBAL driver.
     *
     * @return \Doctrine\DBAL\Driver\PDOMySql\Driver|\Illuminate\Database\PDO\MySqlDriver
     */
    protected function getDoctrineDriver()
    {
        return class_exists(Version::class) ? new DoctrineDriver() : new MySqlDriver();
    }
}
