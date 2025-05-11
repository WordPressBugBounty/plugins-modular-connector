<?php

namespace Modular\Connector\Optimizer;

use Modular\Connector\Models\Comment;
use Modular\Connector\Optimizer\Interfaces\OptimizationInterface;
use Modular\ConnectorDependencies\Carbon\Carbon;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;

class Spam extends Optimization implements OptimizationInterface
{
    public function all()
    {
        return Comment::whereIn('comment_approved', ['spam', 'trash'])
            ->when($this->retainedInterval, function ($query, $retainedInterval) {
                $query->where('comment_date', '<', Carbon::now()->subWeeks($retainedInterval));
            })
            ->count();
    }

    public function optimize(): array
    {
        if ($this->retainedInterval) {
            Log::debug('Deleting spam and trashed comments before ' . $this->retainedInterval . ' weeks...');
        } else {
            Log::debug('Deleting spam and trashed comments...');
        }

        try {
            return [
                'success' => true,
                'result' => [
                    'deleted' => Comment::whereIn('comment_approved', ['spam', 'trash'])
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
