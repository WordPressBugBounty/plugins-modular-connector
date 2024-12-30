<?php

namespace Modular\Connector\Http\Controllers;

use Modular\Connector\Backups\Facades\Backup;
use Modular\Connector\Facades\Manager;
use Modular\Connector\Services\Helpers\File;
use Modular\ConnectorDependencies\Illuminate\Routing\Controller;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Cache;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Config;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Response;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Storage;
use Modular\SDK\Objects\SiteRequest;
use function Modular\ConnectorDependencies\data_get;
use function Modular\ConnectorDependencies\dispatch;

class BackupController extends Controller
{
    /**
     * Returns the WordPress site paths tree as an object in which keys with content value represent folders and keys
     * with 'null' content value represent files.
     *
     * This method is useful when the frontend needs to represent the folders and files tree of its WordPress site in
     * order to allow excluding or including into the backup.
     *
     * @param SiteRequest $modularRequest
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDirectoryTree(SiteRequest $modularRequest)
    {
        $path = $modularRequest->body;
        $path = Storage::path(untrailingslashit($path));

        return File::getTree($path);
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDatabaseTree()
    {
        $tree = Manager::driver('database')->tree();

        return Response::json($tree);
    }

    /**
     * Returns the backup with the provided $payload name content if existing.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function getBackupInformation()
    {
        $information = Backup::information();

        return Response::json($information);
    }

    /**
     * Creates a backup of the WordPress parts provided in $payload,
     * excluding the paths also included in excluded option.
     *
     * @param SiteRequest $modularRequest
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(SiteRequest $modularRequest)
    {
        $payload = $modularRequest->body;
        $requestId = $modularRequest->request_id;

        $driver = $payload->driver ?? Config::get('backup.default');

        Cache::forever('backup.driver', $driver);

        dispatch(fn() => Backup::options($requestId, $payload)->make())
            ->onQueue('backups');

        return Response::json([
            'success' => 'OK',
        ]);
    }

    /**
     * Deletes the backup with the provided $payload name if existing.
     *
     * @param SiteRequest $modularRequest
     * @return \Illuminate\Http\JsonResponse
     */
    protected function destroy(SiteRequest $modularRequest)
    {
        $name = data_get($modularRequest, 'body.name', '');

        dispatch(fn() => Backup::remove($name, true));

        return Response::json([
            'success' => 'OK',
        ]);
    }
}
