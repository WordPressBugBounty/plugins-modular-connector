<?php

namespace Modular\Connector\Optimizer;

use Modular\Connector\Models\CommentMeta;
use Modular\Connector\Optimizer\Interfaces\OptimizationInterface;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;

class AkismetCommentMeta extends Optimization implements OptimizationInterface
{
    public function all()
    {
        return CommentMeta::where('meta_key', 'LIKE', '%akismet%')->count();
    }

    public function optimize(): array
    {
        Log::debug('Performing deletion of akismet comment meta...');

        try {
            return [
                'success' => true,
                'result' => [
                    'deleted' => CommentMeta::where('meta_key', 'LIKE', '%akismet%')->delete(),
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
