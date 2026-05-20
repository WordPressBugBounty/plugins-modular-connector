<?php

namespace Modular\Connector\Jobs;

use Modular\Connector\Events\ManagerRedirectionSynced;
use Modular\Connector\Services\RedirectService;
use Modular\ConnectorDependencies\Illuminate\Bus\Queueable;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Modular\ConnectorDependencies\Illuminate\Contracts\Queue\ShouldQueue;
use Modular\ConnectorDependencies\Illuminate\Foundation\Bus\Dispatchable;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use function Modular\ConnectorDependencies\data_get;
use function Modular\ConnectorDependencies\event;

/**
 * Async processor for the redirect sync endpoint. The SiteRequest carries a
 * presigned S3 `download_link` (30 min TTL); this job downloads the full
 * historical manifest, hands it to RedirectService::sync(), and emits a
 * ManagerRedirectionSynced event with the outcome via the standard webhook
 * pipeline (HookEventListener → HookSendEventJob).
 *
 * Authenticity is provided by:
 *  - JWT on the SiteRequest body (the download_link itself is signed in transit)
 *  - AWS4-HMAC-SHA256 on the S3 presigned URL (the file content is bound to
 *    the signed URL)
 *  - TLS integrity on the download itself
 *
 * No additional HMAC over the body is needed.
 */
class RedirectsSyncJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable;
    use Queueable;

    public $uniqueFor = 3600;

    /**
     * Max bytes accepted from the download. Sized for ~50k entries with
     * realistic patterns; if a manifest exceeds this, the response is
     * truncated by wp_remote_get and json_decode fails — which surfaces
     * as a structured error in the webhook event.
     */
    private const MAX_DOWNLOAD_BYTES = 25 * 1024 * 1024;

    /**
     * HTTP timeout for the manifest download.
     */
    private const DOWNLOAD_TIMEOUT_SECONDS = 30;

    /**
     * @var string
     */
    protected string $mrid;

    /**
     * @var mixed
     */
    protected $payload;

    /**
     * @param string $mrid
     * @param mixed $payload SiteRequest body. Expected key: `download_link` (presigned S3 URL).
     */
    public function __construct(string $mrid, $payload)
    {
        $this->mrid = $mrid;
        $this->payload = $payload;
    }

    /**
     * @return void
     */
    public function handle()
    {
        try {
            $entries = static::fetchManifest($this->payload);

            $result = RedirectService::sync($entries);

            event(new ManagerRedirectionSynced($this->mrid, array_merge(
                ['status' => 'success', 'error' => null],
                $result
            )));
        } catch (\Throwable $e) {
            // Swallow the throwable: the manager learns about failures through
            // the webhook payload, not through Laravel's failed-job machinery.
            // Re-throwing would also trigger the default retry path, which we
            // do not want — a bad manifest will not fix itself on retry, and a
            // presigned link is only valid for 30 min anyway.
            Log::warning('RedirectsSyncJob: sync failed', [
                'mrid' => $this->mrid,
                'error' => $e->getMessage(),
            ]);

            event(new ManagerRedirectionSynced($this->mrid, [
                'status' => 'error',
                'error' => $e->getMessage(),
            ]));
        }
    }

    /**
     * Download and decode the manifest from the presigned URL in the payload.
     *
     * @param mixed $payload
     * @return array
     * @throws \RuntimeException
     */
    private static function fetchManifest($payload): array
    {
        $downloadLink = (string)data_get($payload, 'download_link', '');

        if ($downloadLink === '') {
            throw new \RuntimeException('download_link is required');
        }

        // Defense-in-depth against SSRF: the link is delivered by the API
        // inside a JWT-signed payload (so it would take an API compromise to
        // forge), but allowing arbitrary outbound destinations turns every
        // installation into an SSRF probe against its own private network
        // (cloud metadata, localhost services) the moment that compromise
        // happens. Restrict to https on the Modular API or AWS S3 hosts.
        $parsed = \wp_parse_url($downloadLink);
        $scheme = isset($parsed['scheme']) ? strtolower((string)$parsed['scheme']) : '';
        $host = isset($parsed['host']) ? strtolower((string)$parsed['host']) : '';

        if ($scheme !== 'https' || $host === '') {
            throw new \RuntimeException('download_link must use https');
        }

        if (
            !preg_match('/(^|\.)amazonaws\.com$/', $host)
            && !preg_match('/(^|\.)modulards\.com$/', $host)
        ) {
            throw new \RuntimeException('download_link host not in allowlist: ' . $host);
        }

        $response = \wp_remote_get($downloadLink, [
            'timeout' => self::DOWNLOAD_TIMEOUT_SECONDS,
            'limit_response_size' => self::MAX_DOWNLOAD_BYTES,
            'sslverify' => true,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'ModularConnector/' . MODULAR_CONNECTOR_VERSION,
            ],
        ]);

        if (\is_wp_error($response)) {
            throw new \RuntimeException('manifest download failed: ' . $response->get_error_message());
        }

        $code = (int)\wp_remote_retrieve_response_code($response);

        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException('manifest download returned HTTP ' . $code);
        }

        $body = (string)\wp_remote_retrieve_body($response);
        $entries = json_decode($body, true);

        if (!is_array($entries)) {
            throw new \RuntimeException('manifest is not a JSON array: ' . json_last_error_msg());
        }

        return $entries;
    }

    /**
     * Unique key prevents the same manager request being processed twice if
     * the controller is hit concurrently.
     */
    public function uniqueId(): string
    {
        return $this->mrid;
    }
}
