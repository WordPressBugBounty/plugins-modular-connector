<?php

namespace Modular\Connector\Security\Imunify\Helpers;

use Modular\ConnectorDependencies\Illuminate\Support\Facades\Storage;

/**
 * Utility helpers for reading the consolidated scan manifest and validating
 * disk-relative paths against traversal.
 *
 * Consolidated manifest format: NDJSON, one object per line.
 * Example: {"checksum":"...","type":"f","size":N,"timestamp":N,"path":"disk/relative/path","file_index":N}
 */
class ConsolidatedManifest
{
    /**
     * @var array
     */
    public static $validDisks = ['core', 'plugins', 'themes', 'mu_plugins', 'content', 'uploads'];

    /**
     * Canonical relative path of the consolidated manifest on the `scans` disk.
     * The single source of truth for this filename — every job that needs the
     * consolidated manifest (merge writer, upload, hash submit, cleanup, restore)
     * resolves it through here so the format never drifts.
     *
     * @param string $name Scan name (already sanitized upstream by ScanPart::setPayload).
     * @return string
     */
    public static function pathFor($name)
    {
        return sprintf('%s-scan-manifest-consolidated', $name);
    }

    /**
     * Canonical results-NDJSON path for a scan name on the `scans` disk. Symmetric
     * to `pathFor()` — every read/write of the per-file verdict results uses this
     * helper so the format never drifts.
     *
     * @param string $name
     * @return string
     */
    public static function resultsPathFor($name)
    {
        return sprintf('%s-scan-results', $name);
    }

    /**
     * Build a [file_index => "disk/path"] map by reading the consolidated manifest line by line.
     *
     * @param string $manifestPath Path on the 'backups' disk
     * @return array
     */
    public static function pathByIndex($manifestPath)
    {
        $map = [];

        if (!Storage::disk('scans')->exists($manifestPath)) {
            return $map;
        }

        $absolute = Storage::disk('scans')->path($manifestPath);
        $handle = fopen($absolute, 'r');

        if (!$handle) {
            return $map;
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $row = self::parseRow($line);
                if ($row === null) {
                    continue;
                }

                $map[$row['file_index']] = $row['path'];
            }
        } finally {
            fclose($handle);
        }

        return $map;
    }
    /**
     * Build a [file_index => parsed-row] map. Same iteration cost as `pathByIndex()`
     * but returns the whole row so callers can read `checksum` (precomputed by
     * ScanManifest, useful for sha256 submission) alongside `path`.
     *
     * @param string $manifestPath Path on the 'scans' disk
     * @return array<int, array>
     */
    public static function rowsByIndex($manifestPath)
    {
        $map = [];

        if (!Storage::disk('scans')->exists($manifestPath)) {
            return $map;
        }

        $absolute = Storage::disk('scans')->path($manifestPath);
        $handle = fopen($absolute, 'r');

        if (!$handle) {
            return $map;
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $row = self::parseRow($line);
                if ($row === null) {
                    continue;
                }

                $map[$row['file_index']] = $row;
            }
        } finally {
            fclose($handle);
        }

        return $map;
    }

    /**
     * Convert a per-disk CSV row (as produced by ScanManifest) into a consolidated
     * NDJSON line. Symmetric counterpart of `parseRow()` — this is the writer,
     * that one is the reader.
     *
     * Source format (CSV with `;` delimiter, `"` enclosure with `""` escape for embedded quotes):
     *   checksum;type;size;timestamp;"path"
     * Output format (NDJSON):
     *   {"checksum":"...","type":"f","size":N,"timestamp":N,"path":"disk/...","file_index":N}
     *
     * @param string $line Raw CSV line (no trailing newline expected).
     * @param string $diskType The owning disk; used to prefix `path` so the consolidated
     *                         manifest carries disk-qualified paths.
     * @param int $fileIndex Sequential index assigned by the writer; used later by
     *                       Imunify to reference files by index.
     * @return string|null NDJSON line (no trailing newline) or null when the source row is malformed.
     */
    public static function rewriteCsvRow($line, $diskType, $fileIndex)
    {
        // Use a real CSV parser so paths with embedded `"` (escaped as `""`) are restored intact.
        $cols = str_getcsv($line, ';', '"', '\\');

        if (count($cols) < 5) {
            return null;
        }

        $rawPath = (string)$cols[4];

        return json_encode([
            'checksum' => (string)$cols[0],
            'type' => (string)$cols[1],
            'size' => (int)$cols[2],
            'timestamp' => (int)$cols[3],
            'path' => $diskType . '/' . ltrim($rawPath, '/'),
            'file_index' => $fileIndex,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Parse a single consolidated manifest NDJSON row.
     *
     * `file_id` is the UUID assigned by Imunify after /scan/start. Absent on fresh
     * manifests; PollImunifyJob obtains it on demand from `GET /jobs/{id}/results`
     * and uses `file_index` to look up the precomputed `checksum`/`path` from this manifest.
     *
     * @param string $line
     * @return array|null ['checksum','type','size','timestamp','path','file_index','file_id'] or null on malformed line
     */
    public static function parseRow($line)
    {
        $line = trim($line);
        if ($line === '') {
            return null;
        }

        $row = json_decode($line, true);
        if (!is_array($row) || !isset($row['path']) || !isset($row['file_index'])) {
            return null;
        }

        return [
            'checksum' => isset($row['checksum']) ? (string)$row['checksum'] : '',
            'type' => isset($row['type']) ? (string)$row['type'] : 'f',
            'size' => isset($row['size']) ? (int)$row['size'] : 0,
            'timestamp' => isset($row['timestamp']) ? (int)$row['timestamp'] : 0,
            'path' => (string)$row['path'],
            'file_index' => (int)$row['file_index'],
            'file_id' => isset($row['file_id']) ? (string)$row['file_id'] : null,
        ];
    }

    /**
     * Split "disk/relative" into [disk, relative], rejecting unknown disks.
     *
     * @param string $diskPath
     * @return array [disk|null, relative]
     */
    public static function splitDiskPath($diskPath)
    {
        $slashPos = strpos($diskPath, '/');
        if ($slashPos === false) {
            return [null, $diskPath];
        }

        $disk = substr($diskPath, 0, $slashPos);
        $relative = substr($diskPath, $slashPos + 1);

        if (!in_array($disk, self::$validDisks, true)) {
            return [null, $diskPath];
        }

        return [$disk, $relative];
    }

    /**
     * Validate a disk-relative path: reject `..` traversal, null bytes, and ensure
     * `realpath(disk + relative)` stays inside the disk root. Throws on invalid input.
     *
     * Defensive guard for any code that turns payload-controlled paths into FS operations.
     *
     * @param string $disk
     * @param string $relative
     * @return string Absolute path, guaranteed inside the disk root.
     * @throws \RuntimeException
     */
    public static function resolveSafePath($disk, $relative)
    {
        if (!is_string($disk) || $disk === '' || !in_array($disk, self::$validDisks, true)) {
            throw new \RuntimeException(sprintf('Invalid disk: %s', (string)$disk));
        }

        if (!is_string($relative)) {
            throw new \RuntimeException('Relative path is not a string');
        }

        // Reject `..` only as a path SEGMENT, not as a substring.
        // Otherwise legitimate filenames like `file..backup.zip` get rejected.
        $normalized = str_replace('\\', '/', $relative);
        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '..') {
                throw new \RuntimeException(sprintf('Path traversal rejected: %s', $relative));
            }
        }

        if (strpos($relative, "\0") !== false) {
            throw new \RuntimeException(sprintf('Null byte in path rejected: %s', $relative));
        }

        $diskRoot = Storage::disk($disk)->path('');
        $absolute = Storage::disk($disk)->path($relative);

        $real = @realpath($absolute);
        if ($real !== false) {
            $absolute = $real;
        }

        $diskRootReal = @realpath($diskRoot) ?: $diskRoot;
        $diskRootPrefix = rtrim($diskRootReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        if (strpos($absolute, $diskRootPrefix) !== 0 && $absolute !== rtrim($diskRootReal, DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException(sprintf('Resolved path outside disk %s: %s', $disk, $relative));
        }

        return $absolute;
    }
}
