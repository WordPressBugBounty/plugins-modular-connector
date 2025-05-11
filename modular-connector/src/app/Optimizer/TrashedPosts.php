<?php

namespace Modular\Connector\Optimizer;

use Modular\Connector\Models\Post;
use Modular\Connector\Optimizer\Interfaces\OptimizationInterface;
use Modular\ConnectorDependencies\Carbon\Carbon;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;

class TrashedPosts extends Optimization implements OptimizationInterface
{
    public function all()
    {
        return Post::where('post_status', 'trash')
            ->when($this->retainedInterval, function ($query, $retainedInterval) {
                $query->where('post_modified', '<', Carbon::now()->subWeeks($retainedInterval));
            })
            ->count();
    }

    public function optimize(): array
    {
        if ($this->retainedInterval) {
            Log::debug('Deleting all trashed posts from before ' . $this->retainedInterval . ' weeks...');
        } else {
            Log::debug('Deleting all trashed posts...');
        }

        try {
            return [
                'success' => true,
                'result' => [
                    'deleted' => Post::where('post_status', 'trash')
                        ->when($this->retainedInterval, function ($query, $retainedInterval) {
                            $query->where('post_modified', '<', Carbon::now()->subWeeks($retainedInterval));
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
