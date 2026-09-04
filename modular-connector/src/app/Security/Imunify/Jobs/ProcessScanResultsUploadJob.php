<?php

namespace Modular\Connector\Security\Imunify\Jobs;

use Modular\Connector\Helper\OauthClient;
use Modular\Connector\Security\Facades\Scan;
use Modular\Connector\Security\Imunify\Events\ManagerScanCompleted;
use Modular\Connector\Security\Imunify\Events\ManagerScanPartUpdated;
use Modular\ConnectorDependencies\GuzzleHttp\Client;
use Modular\ConnectorDependencies\GuzzleHttp\Psr7\Utils;
use Modular\ConnectorDependencies\Illuminate\Bus\Queueable;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldQueue;
use Modular\ConnectorDependencies\Illuminate\Foundation\Bus\Dispatchable;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\File as FileFacade;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Storage;
use function Modular\ConnectorDependencies\event;

/**
 * Streams the per-file verdict NDJSON built by `PollImunifyJob::emitDone` to API
 * storage, then emits `ManagerScanCompleted::forFiles` as the terminal scan
 * signal. Uses the `createScanUpload` presigned-URL mechanic with
 * `type: files-results` so API can route this upload to the right bucket.
 *
 * On permanent failure (retries exhausted) the files flow is cancelled via
 * `Scan::cancelFiles` so API gets a terminal signal instead of waiting for a
 * `ManagerScanCompleted` that will never arrive.
 */
class ProcessScanResultsUploadJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    /**
     * @var int
     */
    public $tries = 3;

    /**
     * @var int Seconds between attempts after an uncaught exception (the
     *          presigned URL request or the PUT itself).
     */
    public $backoff = 60;

    /**
     * @var string
     */
    protected $mrid;

    /**
     * @var string
     */
    protected $name;

    /**
     * @var int
     */
    protected $siteScan;

    /**
     * @var string Local path on the `scans` disk of the results NDJSON.
     */
    protected $resultsPath;

    /**
     * @var int Total files in the consolidated manifest.
     */
    protected $totalItems;

    /**
     * @var array Verdict summary built from the final poll response.
     */
    protected $summary;

    /**
     * @var string Verbatim provider status from Imunify (`completed` for a
     *             clean end, `failed` for a salvaged partial). Forwarded
     *             unchanged into ManagerScanCompleted so API can branch on it.
     */
    protected $status;

    /**
     * @param string $mrid
     * @param string $name
     * @param int $siteScan
     * @param string $resultsPath
     * @param int $totalItems
     * @param array $summary
     * @param string $status
     */
    public function __construct($mrid, $name, $siteScan, $resultsPath, $totalItems, array $summary, $status)
    {
        $this->mrid = $mrid;
        $this->name = $name;
        $this->siteScan = (int)$siteScan;
        $this->resultsPath = $resultsPath;
        $this->totalItems = (int)$totalItems;
        $this->summary = $summary;
        $this->status = (string)$status;
        $this->queue = 'backups';
    }

    /**
     * @return void
     */
    public function handle()
    {
        if (Scan::isFilesCancelled($this->name)) {
            return;
        }

        if (!Storage::disk('scans')->exists($this->resultsPath)) {
            Log::error('ProcessScanResultsUploadJob: Results file not found', [
                'mrid' => $this->mrid,
                'results_path' => $this->resultsPath,
            ]);

            Scan::cancelFiles(
                $this->mrid,
                $this->name,
                $this->siteScan,
                'scan_results_missing'
            );

            return;
        }

        $absolute = Storage::disk('scans')->path($this->resultsPath);

        $uploadUri = $this->getUploadUri(FileFacade::basename($this->resultsPath));

        $resource = fopen($absolute, 'r');

        if (!is_resource($resource)) {
            throw new \RuntimeException('Cannot open results NDJSON for upload');
        }

        // try/finally: Guzzle owns the stream lifecycle on success (it closes
        // it after PUT), but if request() throws (network error, 5xx, retry
        // budget exhausted) the underlying fd leaks until GC. Explicit close
        // covers the error path.
        try {
            $stream = Utils::streamFor($resource);

            $guzzle = new Client();
            $guzzle->request('PUT', $uploadUri, ['body' => $stream]);
        } finally {
            if (is_resource($resource)) {
                @fclose($resource);
            }
        }

        Log::debug('ProcessScanResultsUploadJob: Results uploaded', [
            'mrid' => $this->mrid,
            'site_scan' => $this->siteScan,
            'total_items' => $this->totalItems,
        ]);

        event(ManagerScanCompleted::forFiles(
            $this->mrid,
            $this->siteScan,
            $this->totalItems,
            $this->summary,
            $this->status
        ));

        // Local results NDJSON is only useful for the upload; once API has it,
        // we drop the on-disk copy to free space on shared hosts.
        Storage::disk('scans')->delete($this->resultsPath);
    }

    /**
     * @param \Throwable|null $e
     * @return void
     */
    public function failed(\Throwable $e = null)
    {
        Scan::cancelFiles(
            $this->mrid,
            $this->name,
            $this->siteScan,
            $e ? $e->getMessage() : 'results_upload_failed'
        );
    }

    /**
     * Uses `type: files-results` so API can route the upload to the right bucket
     * (per-file verdicts NDJSON).
     *
     * @param string $resultsName
     * @return string
     * @throws \ErrorException
     */
    private function getUploadUri($resultsName)
    {
        $client = OauthClient::getClient();

        return $client->security->createScanUpload($this->siteScan, [
            'type' => ManagerScanPartUpdated::TYPE_FILES,
            'name' => $resultsName,
        ]);
    }
}
