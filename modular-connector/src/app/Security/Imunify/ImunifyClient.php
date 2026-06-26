<?php

namespace Modular\Connector\Security\Imunify;

use Modular\Connector\Security\Facades\Scan;
use Modular\ConnectorDependencies\GuzzleHttp\Client;
use Modular\ConnectorDependencies\GuzzleHttp\Exception\ConnectException;
use Modular\ConnectorDependencies\GuzzleHttp\Exception\GuzzleException;
use Modular\ConnectorDependencies\GuzzleHttp\HandlerStack;
use Modular\ConnectorDependencies\GuzzleHttp\Middleware;
use Modular\ConnectorDependencies\GuzzleHttp\Psr7\Utils;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use Modular\ConnectorDependencies\Psr\Http\Message\RequestInterface;
use Modular\ConnectorDependencies\Psr\Http\Message\ResponseInterface;

class ImunifyClient
{
    /**
     * Default Imunify endpoint. Pinned in code so that the payload signed by Modular
     * API can never redirect outbound traffic — same model as ModularClient.
     */
    public const DEFAULT_API_URL = 'https://connect.imunify.com';

    /**
     * @var Client
     */
    protected $http;

    /**
     * @var string
     */
    protected $baseUrl;

    /**
     * @var string
     */
    protected $siteKey;

    /**
     * Maximum number of times a single Guzzle request will be retried on transient
     * failures (timeouts, 429, 5xx). The job layer (`SubmitToImunifyJob::$tries=3`)
     * still wraps this and Laravel will re-encolar on top.
     */
    private const MAX_RETRIES = 2;

    /**
     * Build a client by looking up the global site_key stored by StartScanJob
     * (and re-stamped by every post-action StartJob). Throws if the key has
     * been evicted — the caller's existing try/catch handles it as a regular
     * failure.
     *
     * Prefer this factory over `new ImunifyClient($siteKey)` so the lookup +
     * null-check stays in one place.
     *
     * @param int $timeout
     * @return self
     */
    public static function make($timeout = 30): self
    {
        $siteKey = Scan::siteKey();

        if ($siteKey === null) {
            throw new \RuntimeException('Imunify site_key not in cache');
        }

        return new self($siteKey, $timeout);
    }

    /**
     * @param string $siteKey
     * @param int $timeout Per-request timeout. 30s is enough server-side for healthy
     *                     batches; transient slowdowns are handled by the retry
     *                     middleware below (with exponential backoff) before
     *                     bubbling up to the job-level retry.
     */
    public function __construct($siteKey, $timeout = 30)
    {
        $this->baseUrl = rtrim(static::DEFAULT_API_URL, '/');
        $this->siteKey = $siteKey;

        $stack = HandlerStack::create();
        $stack->push(Middleware::retry(
            $this->shouldRetryDecider(),
            $this->retryDelay()
        ));

        $this->http = new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => $timeout,
            'handler' => $stack,
            'headers' => [
                'X-Site-Key' => $siteKey,
                'Accept' => 'application/json',
                'User-Agent' => 'ModularConnector/' . (defined('MODULAR_CONNECTOR_VERSION') ? MODULAR_CONNECTOR_VERSION : 'dev'),
            ],
        ]);
    }

    /**
     * Decider: retry transient failures (connection errors, timeouts, 429, 5xx).
     * Stops at MAX_RETRIES attempts.
     *
     * @return callable
     */
    private function shouldRetryDecider()
    {
        $maxRetries = self::MAX_RETRIES;

        return function (
            $retries,
            RequestInterface $request,
            ResponseInterface $response = null,
            $exception = null
        ) use ($maxRetries) {
            if ($retries >= $maxRetries) {
                return false;
            }

            // Network-level failures (DNS, refused, timeout).
            if ($exception instanceof ConnectException) {
                Log::warning('ImunifyClient: retrying after connection error', [
                    'attempt' => $retries + 1,
                    'uri' => (string)$request->getUri(),
                    'error' => $exception->getMessage(),
                ]);

                return true;
            }

            if ($response === null) {
                return false;
            }

            $status = $response->getStatusCode();

            // 429 Too Many Requests + transient 5xx (502 bad gateway, 503 service
            // unavailable, 504 gateway timeout). 500 Internal Server Error is also
            // worth retrying once — Imunify occasionally returns it under load.
            if (in_array($status, [429, 500, 502, 503, 504], true)) {
                Log::warning('ImunifyClient: retrying after transient status', [
                    'attempt' => $retries + 1,
                    'uri' => (string)$request->getUri(),
                    'status' => $status,
                ]);

                return true;
            }

            return false;
        };
    }

    /**
     * Delay between retries in milliseconds. Exponential-ish (500ms, 1500ms) so a
     * saturated Imunify gets a chance to drain before we hammer it again.
     *
     * @return callable
     */
    private function retryDelay()
    {
        return function ($retries) {
            // 0-indexed: first retry waits 500ms, second waits 1500ms.
            return 500 + 1000 * $retries;
        };
    }

    /**
     * POST /api/v1/scan/start
     *
     * Registers a scan job with Imunify. The response is HTTP 202 Accepted: the
     * file inventory is queued, no scanning happens yet. Hashes are submitted in
     * a subsequent call to /jobs/{job_id}/hashes.
     *
     * Sample response:
     *   {
     *     "job_id": "c2b62dd2-1505-4aa1-8c8f-e1083af642c5",
     *     "quota": {
     *       "plan": "scan_and_clean",       // Subscription tier.
     *       "scans_used": 12,                // File scans consumed this period.
     *       "scans_limit": 30,               // File scans allowed per period.
     *       "cleans_used": 0,                // File cleanups consumed.
     *       "cleans_limit": 30,
     *       "db_scans_used": 15,             // DB scans consumed.
     *       "db_scans_limit": 30,
     *       "db_cleans_used": 0,             // DB cleanups consumed.
     *       "db_cleans_limit": 30,
     *       "period": "daily",               // Reset window: "daily" | "monthly".
     *       "resets_in": 86400,              // Seconds until file quotas reset.
     *       "db_resets_in": 86400            // Seconds until DB quotas reset.
     *     }
     *   }
     *
     * @param array $files [{path, file_index, size, modified_at}]
     * @param array $options [force_rescan, webhook_url, webhook_secret]
     * @return array
     * @throws GuzzleException
     */
    public function startScan(array $files, array $options = [])
    {
        $payload = [
            'files' => array_map(function ($f) {
                return [
                    'path' => $f['path'],
                    'file_index' => $f['file_index'],
                    'size' => $f['size'],
                    'modified_at' => $f['modified_at'],
                ];
            }, $files),
        ];

        if (isset($options['force_rescan'])) {
            $payload['force_rescan'] = (bool)$options['force_rescan'];
        }

        if (!empty($options['webhook_url'])) {
            $payload['webhook_url'] = $options['webhook_url'];
        }

        if (!empty($options['webhook_secret'])) {
            $payload['webhook_secret'] = $options['webhook_secret'];
        }

        return $this->postJson('/api/v1/scan/start', $payload);
    }

    /**
     * POST /api/v1/jobs/{jobId}/hashes
     *
     * @param string $jobId
     * @param array $hashes [{file_id, sha256}]
     * @return array
     * @throws GuzzleException
     */
    public function submitHashes($jobId, array $hashes)
    {
        $payload = [
            'hashes' => array_map(function ($h) {
                return [
                    'file_id' => $h['file_id'],
                    'sha256' => $h['sha256'],
                ];
            }, $hashes),
        ];

        return $this->postJson(sprintf('/api/v1/jobs/%s/hashes', $jobId), $payload, [
            'batch_size' => count($hashes),
        ]);
    }

    /**
     * GET /api/v1/jobs/{jobId} — lightweight status endpoint.
     *
     * Used to gate `/hashes` submission: Imunify accepts /scan/start asynchronously
     * (HTTP 202) and only assigns `file_id` UUIDs once the worker transitions the
     * job from "pending" to "processing". Submitting hashes before that returns
     * `"scan job file not found"` for every entry.
     *
     * @param string $jobId
     * @return array Job status: {job_id, status, ...}
     * @throws GuzzleException
     */
    public function getJobStatus($jobId)
    {
        return $this->getJson(sprintf('/api/v1/jobs/%s', $jobId));
    }

    /**
     * GET /api/v1/jobs/{jobId}/results
     *
     * @param string $jobId
     * @return array
     * @throws GuzzleException
     */
    public function getJobResults($jobId)
    {
        return $this->getJson(sprintf('/api/v1/jobs/%s/results', $jobId));
    }

    /**
     * POST /api/v1/jobs/{jobId}/upload (multipart)
     *
     * @param string $jobId
     * @param string $filePath Absolute path on disk
     * @param string $fileId
     * @return array
     * @throws GuzzleException
     */
    public function uploadFile($jobId, $filePath, $fileId)
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new \RuntimeException(sprintf('File not found or not readable: %s', $filePath));
        }

        return $this->postMultipart(sprintf('/api/v1/jobs/%s/upload', $jobId), [
            [
                'name' => 'file',
                'contents' => Utils::streamFor(fopen($filePath, 'r')),
                'filename' => basename($filePath),
            ],
            [
                'name' => 'file_id',
                'contents' => $fileId,
            ],
        ], [
            'file_id' => $fileId,
            'file' => basename($filePath),
        ]);
    }

    /**
     * POST /api/v1/scan/database/start
     *
     * @param string $cmsType e.g. "wordpress"
     * @param string $dbPrefix e.g. "wp_"
     * @param array $tables Discovered table descriptors
     * @param string|null $wordpressPath Optional install path
     * @param array $options [force_rescan, webhook_url, webhook_secret]
     * @return array Response with job_id, status, scan_config
     * @throws GuzzleException
     */
    public function startDatabaseScan($cmsType, $dbPrefix, array $tables, $wordpressPath = null, array $options = [])
    {
        $payload = [
            'cms_type' => $cmsType,
            'db_prefix' => $dbPrefix,
            'tables' => $tables,
        ];

        if ($wordpressPath !== null && $wordpressPath !== '') {
            $payload['wordpress_path'] = $wordpressPath;
        }

        if (isset($options['force_rescan'])) {
            $payload['force_rescan'] = (bool)$options['force_rescan'];
        }

        if (!empty($options['webhook_url'])) {
            $payload['webhook_url'] = $options['webhook_url'];
        }

        if (!empty($options['webhook_secret'])) {
            $payload['webhook_secret'] = $options['webhook_secret'];
        }

        return $this->postJson('/api/v1/scan/database/start', $payload);
    }

    /**
     * POST /api/v1/jobs/{jobId}/db-content
     *
     * Body shape: {"rows":[{"table","column","row_id","content"}],"is_final":bool}
     *
     * Imunify rejects the whole batch with 400 if any row has an empty
     * `table`, `column` or `row_id` (it names the offending field by index,
     * e.g. `rows[3].row_id`). We drop malformed rows client-side so a single
     * bad row from a future custom-table autodiscovery can't kill the batch.
     * Empty `content` is fine — Imunify silently skips those rows and reports
     * the count via the `skipped` response field.
     *
     * @param string $jobId
     * @param array $rows [{table, column, row_id, content}] with HEX-encoded content
     * @param bool $isFinal Set true on last batch to trigger scanning
     * @return array Response body: {accepted: int, skipped?: int, job_status: string}
     * @throws GuzzleException
     */
    public function submitDatabaseContent($jobId, array $rows, $isFinal = false)
    {
        $cleanRows = [];
        $dropped = 0;

        foreach ($rows as $r) {
            $table = isset($r['table']) ? (string)$r['table'] : '';
            $column = isset($r['column']) ? (string)$r['column'] : '';
            $rowId = isset($r['row_id']) ? (string)$r['row_id'] : '';

            if ($table === '' || $column === '' || $rowId === '') {
                $dropped++;
                continue;
            }

            $cleanRows[] = [
                'table' => $table,
                'column' => $column,
                'row_id' => $rowId,
                'content' => isset($r['content']) ? $r['content'] : '',
            ];
        }

        if ($dropped > 0) {
            Log::warning('ImunifyClient: Dropped malformed rows before /db-content submission', [
                'job_id' => $jobId,
                'dropped' => $dropped,
                'remaining' => count($cleanRows),
            ]);
        }

        $payload = [
            'rows' => $cleanRows,
            'is_final' => (bool)$isFinal,
        ];

        $response = $this->postJson(sprintf('/api/v1/jobs/%s/db-content', $jobId), $payload, [
            'rows_count' => count($cleanRows),
            'is_final' => (bool)$isFinal,
        ]);

        // Server-side skip count for empty-content rows. Useful telemetry when
        // expanding autodiscovery to tables whose text columns may legitimately
        // be empty (e.g. nullable VARCHAR fields without a value).
        if (is_array($response) && isset($response['skipped']) && (int)$response['skipped'] > 0) {
            Log::debug('ImunifyClient: /db-content server-side skipped empty-content rows', [
                'job_id' => $jobId,
                'skipped' => (int)$response['skipped'],
                'accepted' => isset($response['accepted']) ? (int)$response['accepted'] : null,
                'job_status' => isset($response['job_status']) ? (string)$response['job_status'] : null,
            ]);
        }

        return $response;
    }

    /**
     * GET /api/v1/jobs/{jobId}/db-status
     *
     * Lightweight status poll for a DB scan job (~200 B). Used to gate the
     * heavier `/db-results` call: `/db-results` grows with threat count and can
     * OOM a PHP worker on heavily infected sites; `/db-status` stays cheap
     * regardless of detection volume. Shape:
     *   {
     *     "job_id": "...",
     *     "status": "running" | "completed" | "failed",
     *     "summary": {"tables_scanned": N, "rows_submitted": N},
     *     "wordpress_path": "..."
     *   }
     *
     * @param string $jobId
     * @return array
     * @throws GuzzleException
     */
    public function getDatabaseJobStatus($jobId)
    {
        return $this->getJson(sprintf('/api/v1/jobs/%s/db-status', $jobId));
    }

    /**
     * GET /api/v1/jobs/{jobId}/db-results
     *
     * Full verdict list for a DB scan job. Only safe to call once the job is in
     * a terminal state ('completed' or 'failed') as confirmed by
     * `getDatabaseJobStatus()`. Shape:
     *   {
     *     "job_id": "...",
     *     "status": "completed" | "failed",
     *     "summary": {"tables_scanned": N, "rows_submitted": N},
     *     "results": [
     *       {"id","table","column","row_id","severity","signature_id","status"},
     *       ...
     *     ]
     *   }
     *
     * @param string $jobId
     * @return array
     * @throws GuzzleException
     */
    public function getDatabaseJobResults($jobId)
    {
        return $this->getJson(sprintf('/api/v1/jobs/%s/db-results', $jobId));
    }

    /**
     * POST with JSON body. Logs request metadata + parsed response.
     *
     * @param string $uri
     * @param array $payload
     * @param array $requestContext Extra fields appended to the request log
     *                              (e.g. counts) without dumping the full body.
     * @return array Parsed response body.
     * @throws GuzzleException
     */
    private function postJson($uri, array $payload, array $requestContext = [])
    {
        return $this->execute('POST', $uri, ['json' => $payload], $requestContext);
    }

    /**
     * POST with multipart body. Same logging contract as postJson.
     *
     * @param string $uri
     * @param array $multipart
     * @param array $requestContext
     * @return array
     * @throws GuzzleException
     */
    private function postMultipart($uri, array $multipart, array $requestContext = [])
    {
        return $this->execute('POST', $uri, ['multipart' => $multipart], $requestContext);
    }

    /**
     * GET request. Logs request + parsed response.
     *
     * @param string $uri
     * @param array $requestContext
     * @return array
     * @throws GuzzleException
     */
    private function getJson($uri, array $requestContext = [])
    {
        return $this->execute('GET', $uri, [], $requestContext);
    }

    /**
     * Centralized request execution: emits a `request` debug log before the call
     * and a `response` debug log after parsing. All Imunify traffic flows through
     * here so every endpoint is observable without per-method log calls.
     *
     * @param string $method
     * @param string $uri
     * @param array $options Guzzle request options.
     * @param array $requestContext Extra metadata for the request log.
     * @return array Parsed response body.
     * @throws GuzzleException
     */
    private function execute($method, $uri, array $options, array $requestContext)
    {
        Log::debug('ImunifyClient: request', array_merge([
            'method' => $method,
            'uri' => $uri,
        ], $requestContext));

        $response = $this->http->request($method, $uri, $options);
        $parsed = $this->parseResponse($response);

        Log::debug('ImunifyClient: response', [
            'method' => $method,
            'uri' => $uri,
            'status' => $response->getStatusCode(),
        ]);

        return $parsed;
    }

    /**
     * @param ResponseInterface $response
     * @return array
     */
    protected function parseResponse(ResponseInterface $response)
    {
        $body = (string)$response->getBody();
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(sprintf('Invalid JSON from Imunify: %s', json_last_error_msg()));
        }

        return is_array($data) ? $data : [];
    }
}
