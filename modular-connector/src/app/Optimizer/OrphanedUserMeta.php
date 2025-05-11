<?php

namespace Modular\Connector\Optimizer;

use Modular\Connector\Models\UserMeta;
use Modular\Connector\Optimizer\Interfaces\OptimizationInterface;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;

class OrphanedUserMeta extends Optimization implements OptimizationInterface
{
    public function all()
    {
        return UserMeta::whereDoesntHave('user')->count();
    }

    public function optimize(): array
    {
        Log::debug('Deleting all orphaned user meta...');

        try {
            return [
                'success' => true,
                'result' => [
                    'deleted' => UserMeta::whereDoesntHave('user')->delete(),
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
