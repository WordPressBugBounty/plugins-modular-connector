<?php

namespace Modular\Connector\Optimizer;

use Modular\Connector\Models\Comment;
use Modular\Connector\Models\Post;
use Modular\Connector\Optimizer\Interfaces\OptimizationInterface;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\DB;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;

class Trackbacks extends Optimization implements OptimizationInterface
{
    public function all()
    {
        return Comment::where('comment_type', 'trackback')->count();
    }

    public function optimize(): array
    {
        Log::debug('Deleting all trackback comments...');

        $prefix = DB::getTablePrefix();
        $postTable = $prefix . (new Post())->getTable();
        $commentTable = $prefix . (new Comment())->getTable();

        try {
            return [
                'success' => true,
                'result' => [
                    'deleted' => Comment::where('comment_type', 'trackback')->delete(),
                    'updated' => DB::statement("UPDATE $postTable SET comment_count = (SELECT COUNT(*) FROM $commentTable WHERE $commentTable.comment_post_ID = $postTable.ID)"),
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
