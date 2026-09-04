<?php

namespace Modular\Connector\Security\Imunify\Exceptions;

/**
 * Imunify answered 403 with `code: quota_exceeded` (scan or cleanup quota
 * depleted) or `code: cleanup_not_allowed` (plan without cleanup). Terminal
 * for the current flow — retrying cannot help until the quota resets — so the
 * jobs cancel with the `quota_exceeded` tag and forward the quota block to the
 * API instead of the raw HTTP message.
 */
class ImunifyQuotaExceededException extends \RuntimeException
{
    public const CODE_QUOTA_EXCEEDED = 'quota_exceeded';
    public const CODE_CLEANUP_NOT_ALLOWED = 'cleanup_not_allowed';

    /**
     * @var string
     */
    protected $quotaCode;

    /**
     * @var array Quota counters as sent by Imunify (scans_used, scans_limit, resets_in, ...).
     */
    protected $quota;

    /**
     * @param string $quotaCode
     * @param string $message
     * @param array $body Decoded error body.
     * @param \Throwable|null $previous
     */
    public function __construct($quotaCode, $message, array $body = [], \Throwable $previous = null)
    {
        $this->quotaCode = (string)$quotaCode;
        $this->quota = self::extractQuota($body);

        parent::__construct((string)$message, 403, $previous);
    }

    /**
     * @param array $body
     * @return bool
     */
    public static function matches(array $body)
    {
        $code = isset($body['code']) ? (string)$body['code'] : '';

        return in_array($code, [self::CODE_QUOTA_EXCEEDED, self::CODE_CLEANUP_NOT_ALLOWED], true);
    }

    /**
     * Imunify puts the counters either under `details`, under `quota`, or at
     * the top level depending on the endpoint. Keep only scalar counters.
     *
     * @param array $body
     * @return array
     */
    private static function extractQuota(array $body)
    {
        $source = $body;

        foreach (['details', 'quota'] as $key) {
            if (isset($body[$key]) && is_array($body[$key])) {
                $source = $body[$key];
                break;
            }
        }

        $quota = [];

        foreach ($source as $key => $value) {
            if (is_scalar($value) && $key !== 'error' && $key !== 'code') {
                $quota[$key] = $value;
            }
        }

        return $quota;
    }

    /**
     * @return string
     */
    public function getQuotaCode()
    {
        return $this->quotaCode;
    }

    /**
     * @return array
     */
    public function getQuota()
    {
        return $this->quota;
    }
}
