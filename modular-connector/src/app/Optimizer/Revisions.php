<?php

namespace Modular\Connector\Optimizer;

use Modular\Connector\Models\Post;
use Modular\Connector\Optimizer\Interfaces\OptimizationInterface;
use Modular\ConnectorDependencies\Carbon\Carbon;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\DB;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;

class Revisions extends Optimization implements OptimizationInterface
{
    public function all()
    {
        if ($this->retainedPostRevisions) {
            return $this->getDataWithRetainedPostRevisions();
        }

        return Post::where('post_type', 'revision')
            ->when($this->retainedInterval, function ($query, $retainedInterval) {
                $query->where('post_modified', '<', Carbon::now()->subWeeks($retainedInterval));
            })
            ->count();
    }

    /**
     * @return int|mixed
     */
    public function getDataWithRetainedPostRevisions()
    {
        // Subquery that gets the count of revisions for each post_parent
        $sub = Post::query()
            ->select('post_parent', DB::raw('COUNT(*) AS cnt'))
            ->where('post_type', 'revision')
            ->groupBy('post_parent');

        // Query that sums only the positive differences (cnt - retained)
        $toDelete = DB::query()
            ->fromSub($sub, 'revision_counts')
            ->selectRaw('SUM(GREATEST(cnt - ?, 0)) AS total', [
                $this->retainedPostRevisions,
            ])
            ->value('total');

        return (int)$toDelete;
    }

    public function optimize(): array
    {
        if ($this->retainedPostRevisions) {
            Log::debug('Deleting revisions, but keeping the last ...', [
                'retainedPostRevisions' => $this->retainedPostRevisions,
            ]);

            return $this->optimizeByPosts();
        }

        if ($this->retainedInterval) {
            Log::debug('Deleting all revisions before  weeks...', [
                'weeks' => $this->retainedInterval,
            ]);
        } else {
            Log::debug('Deleting all revisions...');
        }

        try {
            return [
                'success' => Post::where('post_type', 'revision')
                    ->when($this->retainedInterval, function ($query, $retainedInterval) {
                        $query->where('post_modified', '<', Carbon::now()->subWeeks($retainedInterval));
                    })
                    ->delete(),
            ];
        } catch (\Throwable $e) {
            Log::error($e);

            return [
                'success' => false,
                'error' => $this->formatError($e),
            ];
        }
    }

    public function optimizeByPosts(): array
    {
        $retained = $this->retainedPostRevisions;

        try {
            $prefix = DB::getTablePrefix();
            $table = $prefix . (new Post())->getTable();

            $deleted = DB::affectingStatement(<<<SQL
            DELETE p
            FROM $table AS p
            JOIN (
                SELECT
                    id,
                    ROW_NUMBER() OVER (
                        PARTITION BY post_parent
                        ORDER BY id DESC
                    ) AS rn
                FROM $table
                WHERE post_type = 'revision'
            ) AS ranked ON ranked.id = p.id
            WHERE ranked.rn > ?
        SQL, [$retained]);

            return [
                'success' => true,
                'result' => [
                    'deleted' => $deleted,
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
