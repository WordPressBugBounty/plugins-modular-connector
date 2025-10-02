<?php

namespace Modular\Connector\Http\Controllers;

use Modular\Connector\Jobs\ManagerSafeUpgradeBackupJob;
use Modular\Connector\Jobs\ManagerSafeUpgradeCleanupJob;
use Modular\Connector\Jobs\ManagerSafeUpgradeRolledBackJob;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Response;
use Modular\SDK\Objects\SiteRequest;
use function Modular\ConnectorDependencies\data_get;
use function Modular\ConnectorDependencies\dispatch;

class SafeUpgradeController
{
    /**
     * @param SiteRequest $modularRequest
     * @return \Illuminate\Http\JsonResponse
     */
    public function getSafeUpgradeBackup(SiteRequest $modularRequest)
    {
        $type = data_get($modularRequest->body, 'type');

        dispatch(new ManagerSafeUpgradeBackupJob($modularRequest->request_id, $modularRequest->body, $type));

        return Response::json([
            'success' => 'OK',
        ]);
    }

    /**
     * @param SiteRequest $modularRequest
     * @return \Illuminate\Http\JsonResponse
     */
    public function getSafeUpgradeCleanup(SiteRequest $modularRequest)
    {
        $type = data_get($modularRequest->body, 'type');

        dispatch(new ManagerSafeUpgradeCleanupJob($modularRequest->request_id, $modularRequest->body, $type));

        return Response::json([
            'success' => 'OK',
        ]);
    }

    /**
     * @param SiteRequest $modularRequest
     * @return \Illuminate\Http\JsonResponse
     */
    public function getSafeUpgradeRollback(SiteRequest $modularRequest)
    {
        $type = data_get($modularRequest->body, 'type');

        dispatch(new ManagerSafeUpgradeRolledBackJob($modularRequest->request_id, $modularRequest->body, $type));

        return Response::json([
            'success' => 'OK',
        ]);
    }
}
