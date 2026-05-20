<?php

namespace Modular\Connector\BrokenLinks\Ndjson\Manifest;

/**
 * Append-only NDJSON writer for the BLC discovery pipeline.
 *
 * Concurrency invariant: each scan owns a unique file path
 * (BrokenLinkPart::setManifestPath uses site_broken_link_scan), the producing job
 * runs with tries=1 (no Laravel-driven retries), and ProcessBrokenLinkBatchJob
 * self-redispatches sequentially — a batch finishes (writes its rows, closes
 * the handle) before the next is queued. The writer therefore deliberately
 * avoids flock(): the invariant above is the contract, and a per-row flock
 * would silently no-op on filesystems without working POSIX locks (NFS
 * without lockd, some shared hosts), giving a false sense of safety.
 *
 * Storage facade tradeoff: this class uses raw fopen/fwrite rather than
 * Storage::append() because NDJSON streams thousands of rows per batch and a
 * per-call disk re-open would be O(n) syscalls. The path is still produced
 * via Storage::disk('scans')->path(...) by the caller.
 *
 * Durability: fwrite + fflush flushes user→kernel buffers; we do not fsync.
 * A host crash before kernel writeback can lose the most recent rows. BLC
 * scans are rescannable, so this tradeoff is intentional.
 */
class NdjsonWriter
{
    private const FILE_MODE = 'ab';
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    private const BUFFER_SIZE = 100;

    private string $path;

    /** @var resource|null */
    private $fp = null;

    /** @var string[] */
    private array $buffer = [];

    public function __construct(string $path)
    {
        $this->path = $path;
        $dir = dirname($path);
        if (!is_dir($dir) && !\wp_mkdir_p($dir)) {
            throw new \RuntimeException('NdjsonWriter: cannot create directory ' . $dir);
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * Drop the live resource on serialization. Queue workers serialize the job
     * between handle() and any external retry; a serialized resource leaks the
     * underlying fd until request shutdown otherwise.
     *
     * @return string[]
     */
    public function __sleep(): array
    {
        $this->close();
        return ['path'];
    }

    public function __wakeup(): void
    {
        $this->fp = null;
        $this->buffer = [];
    }

    /**
     * Append one row as a JSON line. UTF-8, no pretty-print, per-row newline.
     * Rows are buffered (BUFFER_SIZE) before being flushed to disk to keep
     * syscall count bounded on large batches; the buffer is flushed on close().
     */
    public function append(array $row): void
    {
        $json = json_encode($row, self::JSON_FLAGS);
        if ($json === false) {
            throw new \RuntimeException('NdjsonWriter: json_encode failed: ' . json_last_error_msg());
        }

        $this->buffer[] = $json . "\n";

        if (count($this->buffer) >= self::BUFFER_SIZE) {
            $this->flush();
        }
    }

    /**
     * Flush the in-memory buffer to disk. Idempotent — safe to call repeatedly.
     */
    public function flush(): void
    {
        if (empty($this->buffer)) {
            return;
        }

        if (!is_resource($this->fp)) {
            $this->fp = fopen($this->path, self::FILE_MODE);
            if (!is_resource($this->fp)) {
                $err = error_get_last()['message'] ?? 'unknown';
                $this->fp = null;
                throw new \RuntimeException('NdjsonWriter: cannot open ' . $this->path . ': ' . $err);
            }
        }

        $payload = implode('', $this->buffer);
        $expected = strlen($payload);

        $offsetBefore = ftell($this->fp);
        $written = fwrite($this->fp, $payload);

        if ($written === false || $written !== $expected) {
            // Roll back the partial write so the next append does not produce
            // torn JSON ('{"a":1}\n{"b':2}…' on a corrupted scan file).
            if ($offsetBefore !== false) {
                ftruncate($this->fp, $offsetBefore);
            }
            $this->buffer = [];
            $err = error_get_last()['message'] ?? 'unknown';
            throw new \RuntimeException(sprintf(
                'NdjsonWriter: fwrite failed or partial write on %s (wrote %s of %d): %s',
                $this->path,
                var_export($written, true),
                $expected,
                $err
            ));
        }

        $this->buffer = [];
    }

    /**
     * Flush remaining buffered rows and release the file handle. The destructor
     * calls this; explicit calls from job code make ownership obvious.
     */
    public function close(): void
    {
        if (!empty($this->buffer)) {
            try {
                $this->flush();
            } catch (\Throwable $e) {
                // Don't mask the underlying handle-close path; rethrow only
                // after attempting to release fp.
                $this->releaseHandle();
                throw $e;
            }
        }
        $this->releaseHandle();
    }

    public function path(): string
    {
        return $this->path;
    }

    public function exists(): bool
    {
        clearstatcache(true, $this->path);
        return is_file($this->path);
    }

    /**
     * Remove the underlying file. Closes the handle first so the unlink is
     * not racing the writer's own descriptor.
     */
    public function delete(): void
    {
        $this->close();
        clearstatcache(true, $this->path);
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    /**
     * Size in bytes including any buffered rows already on disk. Flushes the
     * in-memory buffer first so the value reflects callers' append() history.
     */
    public function size(): int
    {
        $this->flush();
        if (is_resource($this->fp)) {
            fflush($this->fp);
        }
        clearstatcache(true, $this->path);
        return is_file($this->path) ? (int)filesize($this->path) : 0;
    }

    private function releaseHandle(): void
    {
        if (is_resource($this->fp)) {
            fflush($this->fp);
            fclose($this->fp);
        }
        $this->fp = null;
    }
}
