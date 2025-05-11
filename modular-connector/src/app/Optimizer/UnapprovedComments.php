<?php

namespace Modular\Connector\Optimizer;

use Modular\Connector\Models\Comment;
use Modular\Connector\Optimizer\Interfaces\OptimizationInterface;
use Modular\ConnectorDependencies\Carbon\Carbon;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;

class UnapprovedComments extends Optimization implements OptimizationInterface
{
    public function all()
    {
        return Comment::where('comment_approved', '0')
            ->when($this->retainedInterval, function ($query, $retainedInterval) {
                $query->where('comment_date', '<', Carbon::now()->subWeeks($retainedInterval));
            })
            ->count();
    }

    public function optimize(): array
    {
        if ($this->retainedInterval) {
            Log::debug('Deleting all unapproved comments from before weeks...', [
                'weeks' => $this->retainedInterval,
            ]);
        } else {
            Log::debug('Deleting all unapproved comments...');
        }
        
        try {
            return [
                'success' => true,
                'result' => [
                    'deleted' => Comment::where('comment_approved', '0')
                        ->when($this->retainedInterval, function ($query, $retainedInterval) {
                            $query->where('comment_date', '<', Carbon::now()->subWeeks($retainedInterval));
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
