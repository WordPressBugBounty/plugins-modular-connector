<?php

namespace Modular\Connector\Optimizer;

use Modular\Connector\Facades\Database;
use Modular\Connector\Optimizer\Interfaces\OptimizationInterface;
use Modular\ConnectorDependencies\Illuminate\Support\Collection;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\DB;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;

class OptimizeOverheadTables extends Optimization implements OptimizationInterface
{
    public function all()
    {
        $tables = $this->getTables();
        $tables = array_filter($tables, fn($table) => $table->isOptimizable && $table->isTypeSupported);

        return array_reduce($tables, fn($carry, $table) => $table->Data_free + $carry, 0);
    }

    /**
     * Get all tables in the database
     *
     * @return array
     */
    public function getTables()
    {
        $tableStatus = Database::showTableStatus();
        $tablePrefix = DB::getTablePrefix();

        foreach ($tableStatus as $index => $table) {
            $tableName = $table->Name;

            $includeTable = stripos($tableName, $tablePrefix) === 0;

            if (!$includeTable && !empty($tablePrefix)) {
                unset($tableStatus[$index]);
                continue;
            }

            $tableStatus[$index]->Engine = Database::getTableEngine($tableName);
            $tableStatus[$index]->isOptimizable = Database::isOptimizable($tableName);
            $tableStatus[$index]->isTypeSupported = Database::supportsTableTypeOptimization($tableName);
        }

        return $tableStatus;
    }

    /**
     * @return void
     * @throws \Exception
     */
    public function optimize(): array
    {
        $tables = Collection::make($this->getTables());

        Log::debug('Optimizing database tables...');

        try {
            return [
                'success' => true,
                'result' => $tables->map(fn($table) => $this->optimizeTable($table))->values()->toArray(),
            ];
        } catch (\Throwable $e) {
            Log::error($e);

            return [
                'success' => false,
                'error' => $this->formatError($e),
            ];
        }
    }

    /**
     * @param $table
     * @return mixed
     */
    public function optimizeTable($table)
    {
        if (!$table->isOptimizable || !$table->isTypeSupported) {
            Log::debug('Table  is not optimizable. Skipping...', ['table' => $table->Name]);

            return;
        }

        Log::debug('Optimizing table...', [
            'table' => $table->Name,
        ]);

        return [
            'table' => $table->Name,
            'result' => DB::statement("OPTIMIZE TABLE `{$table->Name}`"),
            'data_free' => $table->Data_free,
            'engine' => $table->Engine,
            'is_optimizable' => $table->isOptimizable,
            'is_type_supported' => $table->isTypeSupported,
        ];
    }
}
