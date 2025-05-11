<?php

namespace Modular\Connector\Optimizer;

use Modular\Connector\Models\TermRelationship;
use Modular\Connector\Optimizer\Interfaces\OptimizationInterface;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;

class OrphanedRelationshipData extends Optimization implements OptimizationInterface
{
    public function all()
    {
        return TermRelationship::where('term_taxonomy_id', 1)
            ->whereDoesntHave('post')
            ->count();
    }

    public function optimize(): array
    {
        Log::debug('Deleting all orphaned relationships...');

        try {
            return [
                'success' => true,
                'result' => [
                    'deleted' => TermRelationship::where('term_taxonomy_id', 1)
                        ->whereDoesntHave('post')
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
