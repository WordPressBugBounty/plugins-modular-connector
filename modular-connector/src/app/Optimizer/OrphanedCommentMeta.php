<?php

namespace Modular\Connector\Optimizer;

use Modular\Connector\Models\CommentMeta;
use Modular\Connector\Optimizer\Interfaces\OptimizationInterface;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;

class OrphanedCommentMeta extends Optimization implements OptimizationInterface
{
    public function all()
    {
        return CommentMeta::whereDoesntHave('comment')->count();
    }

    public function optimize(): array
    {
        Log::debug('Deleting orphaned comment meta...');

        try {
            return [
                'success' => true,
                'result' => [
                    'deleted' => CommentMeta::whereDoesntHave('comment')->delete(),
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
