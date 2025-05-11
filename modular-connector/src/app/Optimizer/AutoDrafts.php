<?php

namespace Modular\Connector\Optimizer;

use Modular\Connector\Models\Post;
use Modular\Connector\Optimizer\Interfaces\OptimizationInterface;
use Modular\ConnectorDependencies\Carbon\Carbon;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;

class AutoDrafts extends Optimization implements OptimizationInterface
{
    public function all()
    {
        return Post::where('post_status', 'auto-draft')
            ->when($this->retainedInterval, function ($query, $retainedInterval) {
                $query->where(
                    'post_modified',
                    '<',
                    Carbon::now()->subWeeks($retainedInterval)
                );
            })
            ->count();
    }

    public function optimize(): array
    {
        if (!$this->retainedInterval) {
            Log::debug('Deleting all auto drafts...');
        } else {
            Log::debug('Deleting all auto drafts from before ' . $this->retainedInterval . ' weeks...');
        }

        try {
            return [
                'success' => true,
                'result' => [
                    'deleted' => Post::where('post_status', 'auto-draft')
                        ->when($this->retainedInterval, function ($query, $retainedInterval) {
                            $query->where(
                                'post_modified',
                                '<',
                                Carbon::now()->subWeeks($retainedInterval)
                            );
                        })
                        ->delete(),
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
