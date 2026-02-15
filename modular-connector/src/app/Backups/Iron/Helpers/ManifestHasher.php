<?php

namespace Modular\Connector\Backups\Iron\Helpers;

use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;

/**
 * Optimized hash computation for backup manifests.
 *
 * Always computes full file hash with adaptive optimization for large files.
 * Algorithm selection handled by BackupPart::selectHashAlgorithm().
 *
 * Compatible with PHP 7.4+
 */
class ManifestHasher
{
    /**
     * @var int Threshold to switch to fastest algorithm in adaptive mode (100MB)
     */
    private const ADAPTIVE_THRESHOLD = 100 * 1024 * 1024;

    /** @var
     * int Buffer size for streaming I/O (8MB for optimal performance)
     */
    private const IO_BUFFER_SIZE = 8 * 1024 * 1024;

    /**
     * @var string Hash algorithm to use
     */
    private $algorithm;

    /**
     * @var string Base algorithm (before adaptive switching)
     */
    private $baseAlgorithm;

    /**
     * @var bool Force full hash even for large files (for security analysis)
     */
    private $forceFullHash;

    /**
     * @param string $algorithm Hash algorithm (md5|sha256|xxh128) - must be concrete, not 'auto'
     * @param bool $forceFullHash Force full hash without adaptive optimization (for security analysis)
     */
    public function __construct(string $algorithm, bool $forceFullHash = false)
    {
        // Algorithm should already be resolved by BackupPart::selectHashAlgorithm()
        // No need to handle 'auto' here
        $this->baseAlgorithm = $algorithm;
        $this->algorithm = $algorithm;
        $this->forceFullHash = $forceFullHash;

        Log::debug('ManifestHasher initialized', [
            'algorithm' => $this->algorithm,
            'forceFullHash' => $forceFullHash,
        ]);
    }

    /**
     * Main entry point. Always computes full file hash.
     *
     * @param \SplFileInfo $item
     * @return string|null
     */
    public function computeHash(\SplFileInfo $item): ?string
    {
        $size = $item->getSize();
        $path = $item->getRealPath();

        // Validate file exists and is readable
        if ($size <= 0 || $path === false) {
            Log::warning('ManifestHasher: File not readable', ['path' => $item->getPathname()]);

            return null;
        }

        // Adaptive optimization: switch to fastest algorithm for large files
        // (unless forced to use base algorithm for security analysis)
        if (!$this->forceFullHash && $size >= self::ADAPTIVE_THRESHOLD) {
            $this->algorithm = $this->selectFastestAlgorithm();
        } else {
            $this->algorithm = $this->baseAlgorithm;
        }

        $hash = $this->computeFullHash($item);

        return $hash !== false ? $hash : null;
    }

    /**
     * Select the fastest available algorithm for large files.
     * Priority: xxh128 > md5
     *
     * Only switches if using sha256 (the only slow algorithm we accept).
     * Fast algorithms (xxh128, md5) are kept as-is.
     *
     * @return string
     */
    private function selectFastestAlgorithm(): string
    {
        // Only switch if current algorithm is sha256 (slow)
        // xxh128 and md5 are already fast, no need to switch
        if ($this->baseAlgorithm !== 'sha256') {
            return $this->baseAlgorithm;
        }

        // Switch to fastest available algorithm
        $available = hash_algos();
        $fastAlgorithms = ['xxh128', 'md5'];

        foreach ($fastAlgorithms as $algo) {
            if (in_array($algo, $available, true)) {
                Log::debug('ManifestHasher: Switching from sha256 to fast algorithm for large file', [
                    'from' => 'sha256',
                    'to' => $algo,
                ]);
                return $algo;
            }
        }

        // Keep sha256 if no fast algorithm available (very unlikely)
        return $this->baseAlgorithm;
    }

    /**
     * Compute full file hash with optimized I/O.
     *
     * @param \SplFileInfo $item
     * @return string|false
     */
    private function computeFullHash(\SplFileInfo $item)
    {
        $path = $item->getRealPath();
        $size = $item->getSize();

        // For smaller files, use hash_file()
        if ($size < self::ADAPTIVE_THRESHOLD) {
            error_clear_last();
            $hash = hash_file($this->algorithm, $path);

            if ($hash === false) {
                $error = error_get_last();

                Log::warning('ManifestHasher: hash_file() failed', [
                    'path' => $path,
                    'size' => $size,
                    'algorithm' => $this->algorithm,
                    'error' => $error['message'] ?? 'Unknown error',
                ]);

                return false;
            }

            return $hash;
        }

        // For large files, use streaming with large buffers to reduce I/O overhead
        return $this->computeStreamingHash($path, $size);
    }

    /**
     * Compute hash using streaming I/O with large buffers.
     * Reduces syscalls and improves performance on large files.
     *
     * @param string $path
     * @param int $size
     * @return string|false
     */
    private function computeStreamingHash(string $path, int $size)
    {
        $ctx = hash_init($this->algorithm);

        error_clear_last();
        $fileHandler = fopen($path, 'rb');

        if ($fileHandler === false) {
            $error = error_get_last();

            Log::warning('ManifestHasher: fopen() failed during streaming', [
                'path' => $path,
                'size' => $size,
                'algorithm' => $this->algorithm,
                'error' => $error['message'] ?? 'Unknown error',
            ]);

            return false;
        }

        try {
            // Read file in 8MB chunks to minimize syscalls
            while (!feof($fileHandler)) {
                $chunk = fread($fileHandler, self::IO_BUFFER_SIZE);

                if ($chunk === false) {
                    Log::warning('ManifestHasher: fread() failed during streaming', [
                        'path' => $path,
                        'size' => $size,
                        'algorithm' => $this->algorithm,
                    ]);
                    return false;
                }

                hash_update($ctx, $chunk);
            }

            return hash_final($ctx);
        } finally {
            fclose($fileHandler);
        }
    }

    /**
     * Get the algorithm being used.
     *
     * @return string
     */
    public function getAlgorithm(): string
    {
        return $this->algorithm;
    }

    /**
     * Check if full hash is forced (no adaptive optimization).
     *
     * @return bool
     */
    public function isForceFullHash(): bool
    {
        return $this->forceFullHash;
    }
}
