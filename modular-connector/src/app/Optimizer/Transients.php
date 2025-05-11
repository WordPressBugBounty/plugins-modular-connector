<?php

namespace Modular\Connector\Optimizer;

use Modular\Connector\Models\Option;
use Modular\Connector\Optimizer\Interfaces\OptimizationInterface;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\DB;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;

class Transients extends Optimization implements OptimizationInterface
{
    public function all()
    {
        $tablePrefix = DB::getTablePrefix();

        $transients = Option::from('options as a')
            ->leftJoin('options as b', function ($join) use ($tablePrefix) {
                $join->whereRaw("{$tablePrefix}b.option_name = CONCAT(
                    '_transient_timeout_',
                    SUBSTRING(
                        {$tablePrefix}a.option_name,
                        LENGTH('_transient_') + 1
                    )
                )");
            })
            ->where('a.option_name', 'LIKE', '_transient_%')
            ->where('a.option_name', 'NOT LIKE', '_transient_timeout_%')
            ->when(
                $this->removeOnlyExpired,
                fn($query) => $query->whereRaw("{$tablePrefix}b.option_value < UNIX_TIMESTAMP()")
            )
            ->count();

        $timeouts = Option::where('option_name', 'LIKE', '_transient_timeout_%')
            ->when($this->removeOnlyExpired, function ($query) {
                $query->where('option_value', '<', time());
            })
            ->count();

        return [
            'transients' => $transients,
            'timeouts' => $timeouts,
        ];
    }

    public function optimize(): array
    {
        $tablePrefix = DB::getTablePrefix();

        if ($this->removeOnlyExpired) {
            Log::debug('Deleting all expired transients...');
        } else {
            Log::debug('Deleting all transients...');
        }

        // Clean transients
        try {
            // Clean transient timeouts
            Log::debug('Clearing transient timeouts...');;

            return [
                'success' => true,
                'result' => [
                    'deleted' => [
                        'transients' => Option::from('options as a')
                            ->leftJoin('options as b', function ($join) use ($tablePrefix) {
                                $join->whereRaw("{$tablePrefix}b.option_name = CONCAT(
                                '_transient_timeout_',
                                SUBSTRING(
                                    {$tablePrefix}a.option_name,
                                    LENGTH('_transient_') + 1
                                )
                            )");
                            })
                            ->where('a.option_name', 'LIKE', '_transient_%')
                            ->where('a.option_name', 'NOT LIKE', '_transient_timeout_%')
                            ->when($this->removeOnlyExpired, function ($query) use ($tablePrefix) {
                                $query->whereRaw("{$tablePrefix}b.option_value < UNIX_TIMESTAMP()");
                            })
                            ->delete(),
                        'timeouts' => Option::where('option_name', 'LIKE', '_transient_timeout_%')
                            ->when($this->removeOnlyExpired, function ($query) {
                                $query->where('option_value', '<', time());
                            })
                            ->delete(),
                    ],
                ],
            ];
        } catch (\Throwable $e) {
            Log::error($e);

            return [
                'success' => false,
                'error' => $this->formatError($e),
            ];
        }
    }
}
