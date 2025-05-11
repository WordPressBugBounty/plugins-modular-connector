<?php

namespace Modular\Connector\Optimizer;

use Modular\Connector\Models\PostMeta;
use Modular\Connector\Optimizer\Interfaces\OptimizationInterface;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;

class OrphanedPostMeta extends Optimization implements OptimizationInterface
{
    public function all()
    {
        return PostMeta::whereDoesntHave('post')->count();
    }

    public function optimize(): array
    {
        Log::debug('Deleting all orphaned post meta...');

        try {
            return [
                'success' => true,
                'result' => [
                    'deleted' => PostMeta::whereDoesntHave('post')->delete(),
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
