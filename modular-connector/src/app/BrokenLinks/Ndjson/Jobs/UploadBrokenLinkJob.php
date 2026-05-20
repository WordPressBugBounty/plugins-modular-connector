<?php

namespace Modular\Connector\BrokenLinks\Ndjson\Jobs;

use Modular\Connector\BrokenLinks\Ndjson\BrokenLinkPart;
use Modular\Connector\BrokenLinks\Ndjson\Events\ManagerBrokenLinkPartUpdated;
use Modular\Connector\Helper\OauthClient;
use Modular\ConnectorDependencies\GuzzleHttp\Client;
use Modular\ConnectorDependencies\GuzzleHttp\Psr7\Utils;
use Modular\ConnectorDependencies\Illuminate\Bus\Queueable;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldQueue;
use Modular\ConnectorDependencies\Illuminate\Foundation\Bus\Dispatchable;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;

class UploadBrokenLinkJob implements ShouldQueue
{
    use Queueable, Dispatchable;

    /**
     * External API hop (createUpload + S3 PUT). Same retry budget as the
     * Imunify-facing jobs (PollImunifyJob, ProcessScanResultsUploadJob). The
     * S3 PUT is idempotent on a fixed signed URL, and `step=upload` transitions
     * (`in_progress` → `done`) are re-emittable — duplicate webhooks are
     * tolerable but a transient network blip should not fail the part.
     */
    public $tries = 3;

    public BrokenLinkPart $part;

    public function __construct(BrokenLinkPart $part)
    {
        $this->part = $part;
    }

    public function handle(): void
    {
        $part = $this->part;

        if ($part->isCancelled()) {
            return;
        }

        // Bump mtime so AutoCleanUpCommand does not sweep the manifest while
        // an upload is in flight; this job only reads the NDJSON.
        @touch($part->manifestPath);

        $part->markAs(
            ManagerBrokenLinkPartUpdated::STEP_UPLOAD,
            ManagerBrokenLinkPartUpdated::STATUS_IN_PROGRESS
        );

        $uploadUrl = $this->requestUploadUrl();
        $this->putToSignedUrl($uploadUrl, $part->manifestPath);

        $part->markAs(
            ManagerBrokenLinkPartUpdated::STEP_UPLOAD,
            ManagerBrokenLinkPartUpdated::STATUS_DONE
        );
    }

    public function failed(\Throwable $e): void
    {
        $part = $this->part;

        Log::error('BLC UploadBrokenLinkJob failed', [
            'site_broken_link_scan' => $part->siteBrokenLinkScan,
            'source_type' => $part->postType,
            'error' => $e->getMessage(),
        ]);

        try {
            $part->markAsFailed(ManagerBrokenLinkPartUpdated::STEP_UPLOAD, $e);
        } catch (\Throwable $notifyError) {
            Log::error('BLC UploadBrokenLinkJob::failed: notify also failed', [
                'site_broken_link_scan' => $part->siteBrokenLinkScan,
                'source_type' => $part->postType,
                'error' => $notifyError->getMessage(),
            ]);
        }
    }

    /**
     * The API receives the source_type alongside the scan id so it can mint a
     * unique signed URL per part (Backups-style — one upload destination per
     * part), instead of clobbering the per-scan S3 key.
     */
    private function requestUploadUrl(): string
    {
        $client = OauthClient::getClient();
        $response = $client->brokenLinks->createUpload(
            $this->part->siteBrokenLinkScan,
            ['source_type' => $this->part->postType]
        );

        if (!is_string($response) || $response === '') {
            throw new \RuntimeException('createUpload returned invalid URL');
        }

        return $response;
    }

    private function putToSignedUrl(string $uploadUrl, string $localPath): void
    {
        // Raw fopen/fclose: Guzzle needs a streamable resource for the PUT body,
        // and the Storage facade has no equivalent that yields one for a remote upload.
        $resource = fopen($localPath, 'rb');
        if ($resource === false) {
            throw new \RuntimeException('Cannot open NDJSON for upload: ' . $localPath);
        }

        try {
            $stream = Utils::streamFor($resource);
            $guzzle = new Client();
            $guzzle->request('PUT', $uploadUrl, [
                'body' => $stream,
                'headers' => ['Content-Type' => 'application/x-ndjson'],
            ]);
        } finally {
            if (is_resource($resource)) {
                @fclose($resource);
            }
        }
    }
}
