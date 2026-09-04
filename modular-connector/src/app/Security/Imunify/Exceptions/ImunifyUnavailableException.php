<?php

namespace Modular\Connector\Security\Imunify\Exceptions;

/**
 * Imunify answered 429 or 503 and the transport-level retries could not absorb
 * it (no Retry-After, or one longer than the tick budget). Nothing was
 * committed on the provider side, so the caller can reissue the same request
 * later — the jobs re-dispatch themselves with `delaySeconds()` instead of
 * treating it as a scan failure.
 */
class ImunifyUnavailableException extends \RuntimeException
{
    public const REASON_LOAD_SHED = 'load_shed';
    public const REASON_MAINTENANCE = 'maintenance';
    public const REASON_RATE_LIMITED = 'rate_limited';
    public const REASON_TRANSIENT = 'transient';

    /**
     * Delay applied when Imunify sends no Retry-After.
     */
    private const DEFAULT_DELAY_SECONDS = 60;

    /**
     * Floor: the queue is ticked once a minute by wp-cron, shorter delays only
     * burn a tick re-hitting a backend that just asked us to wait.
     */
    private const MIN_DELAY_SECONDS = 30;

    /**
     * Ceiling: stays under PollImunifyJob::$uniqueFor (600 s) so the unique lock
     * is still held when the delayed job becomes available and a kick from the
     * API cannot start a parallel poll chain in the meantime.
     */
    private const MAX_DELAY_SECONDS = 540;

    /**
     * @var string
     */
    protected $uri;

    /**
     * @var int
     */
    protected $statusCode;

    /**
     * @var string One of the REASON_* constants.
     */
    protected $reason;

    /**
     * @var int|null Raw server-stated Retry-After in seconds, null when absent.
     */
    protected $retryAfterSeconds;

    /**
     * @param string $uri
     * @param int $statusCode
     * @param string $reason
     * @param int|null $retryAfterSeconds
     * @param \Throwable|null $previous
     */
    public function __construct($uri, $statusCode, $reason, $retryAfterSeconds = null, \Throwable $previous = null)
    {
        $this->uri = (string)$uri;
        $this->statusCode = (int)$statusCode;
        $this->reason = (string)$reason;
        $this->retryAfterSeconds = $retryAfterSeconds === null ? null : max(0, (int)$retryAfterSeconds);

        parent::__construct(
            sprintf('Imunify unavailable (%d %s) on %s', $this->statusCode, $this->reason, $this->uri),
            $this->statusCode,
            $previous
        );
    }

    /**
     * Classify a 429/503 by its body: `code: load_shed` (backend saturated),
     * `error: maintenance`, a plain 429 (edge rate limiter) or anything else.
     *
     * @param int $statusCode
     * @param array $body Decoded JSON body, empty when not JSON.
     * @return string
     */
    public static function reasonFor($statusCode, array $body)
    {
        $code = isset($body['code']) ? (string)$body['code'] : '';
        $error = isset($body['error']) ? (string)$body['error'] : '';

        if ($code === self::REASON_LOAD_SHED) {
            return self::REASON_LOAD_SHED;
        }

        if ($error === self::REASON_MAINTENANCE || $code === self::REASON_MAINTENANCE) {
            return self::REASON_MAINTENANCE;
        }

        if ((int)$statusCode === 429) {
            return self::REASON_RATE_LIMITED;
        }

        return self::REASON_TRANSIENT;
    }

    /**
     * @return string
     */
    public function getUri()
    {
        return $this->uri;
    }

    /**
     * @return int
     */
    public function getStatusCode()
    {
        return $this->statusCode;
    }

    /**
     * @return string
     */
    public function getReason()
    {
        return $this->reason;
    }

    /**
     * @return int|null
     */
    public function getRetryAfterSeconds()
    {
        return $this->retryAfterSeconds;
    }

    /**
     * Seconds to wait before re-dispatching the job: the server's Retry-After
     * when present, clamped so it fits the queue cadence and the unique lock.
     *
     * @return int
     */
    public function delaySeconds()
    {
        $seconds = $this->retryAfterSeconds === null || $this->retryAfterSeconds === 0
            ? self::DEFAULT_DELAY_SECONDS
            : $this->retryAfterSeconds;

        return max(self::MIN_DELAY_SECONDS, min(self::MAX_DELAY_SECONDS, $seconds));
    }

    /**
     * Fields worth logging alongside the message.
     *
     * @return array
     */
    public function context()
    {
        return [
            'uri' => $this->uri,
            'status' => $this->statusCode,
            'reason' => $this->reason,
            'retry_after' => $this->retryAfterSeconds,
            'delay' => $this->delaySeconds(),
        ];
    }
}
