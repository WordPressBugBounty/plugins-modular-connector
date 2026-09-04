<?php

namespace Modular\Connector\Security\Imunify\Helpers;

use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Storage;
use Modular\ConnectorDependencies\Illuminate\Support\Str;

/**
 * Utility helpers for reading the consolidated scan manifest and validating
 * absolute filesystem paths against traversal.
 *
 * Consolidated manifest format: NDJSON, one object per line.
 * Example: {"checksum":"...","type":"f","size":N,"timestamp":N,"path":"/var/www/html/wp-content/plugins/foo/bar.php","file_index":N}
 *
 * The `path` field is the **absolute filesystem path** as resolved through
 * `Storage::disk($disk)->path($diskRelative)` at consolidation time. We emit
 * absolute paths (not WP-relative) for two reasons:
 *   1. **Same shape everywhere** — managed-WP hostings put disks on disparate
 *      mount points (WP Engine: core at `/common/nt/wp/6.7.1`, wp-content at
 *      `/var/www/html`). A WP-relative computation would either silently drop
 *      those files or emit inconsistent shapes across the manifest. Absolute
 *      paths are the lowest common denominator.
 *   2. **What hosting-portal users recognize** — cPanel / Plesk / Pressable
 *      file managers show absolute paths; the API can surface them verbatim.
 *
 * Imunify is fed the same absolute path verbatim, so no transformation at the
 * Imunify boundary either.
 */
class ConsolidatedManifest
{
    /**
     * Disks expected to appear in scan manifests. Used by `resolveSafePath()` to
     * validate that a manifest-stored absolute path lives inside one of our
     * configured Storage roots — defensive guard against a future bug letting
     * arbitrary paths into the manifest reach FS operations.
     *
     * @var string[]
     */
    private static $diskNames = ['core', 'plugins', 'themes', 'mu_plugins', 'content', 'uploads'];

    /**
     * Per-request cache of the disk roots, string form and realpath form.
     *
     * @var array<string, string[]>|null
     */
    private static $diskRoots;

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
     * Build a [file_index => absolute_path] map by reading the consolidated manifest line by line.
     *
     * @param string $manifestPath Path on the 'scans' disk
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
     *   {"checksum":"...","type":"f","size":N,"timestamp":N,"path":"/var/www/html/...","file_index":N}
     *
     * The path is resolved from disk-relative to absolute via
     * `Storage::disk($diskType)->path(...)` so the consolidated manifest is
     * uniform regardless of host layout. Imunify and the API both consume the
     * absolute path verbatim — no further transformation downstream.
     *
     * @param string $line Raw CSV line (no trailing newline expected).
     * @param string $diskType The owning disk; routed through the Storage facade
     *                         to resolve the file's absolute filesystem path.
     * @param int $fileIndex Sequential index assigned by the writer; used later by
     *                       Imunify to reference files by index.
     * @return string|null NDJSON line (no trailing newline) or null when the source row
     *                     is malformed or the disk is unknown.
     */
    public static function rewriteCsvRow($line, $diskType, $fileIndex)
    {
        // Use a real CSV parser so paths with embedded `"` (escaped as `""`) are restored intact.
        $cols = str_getcsv($line, ';', '"', '\\');

        if (count($cols) < 5) {
            return null;
        }

        $rawPath = (string)$cols[4];

        if (!in_array($diskType, self::$diskNames, true)) {
            Log::warning('ConsolidatedManifest: Skipping row with unknown disk', [
                'disk' => $diskType,
                'raw_path' => $rawPath,
            ]);
            return null;
        }

        $absolute = Storage::disk($diskType)->path(ltrim($rawPath, '/'));

        return json_encode([
            'checksum' => (string)$cols[0],
            'type' => (string)$cols[1],
            'size' => (int)$cols[2],
            'timestamp' => (int)$cols[3],
            'path' => $absolute,
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
     * Validate a manifest-stored absolute path before using it for FS operations
     * (Imunify content upload, etc.). Rejects `..` segments and null bytes, then
     * verifies the path lives inside one of our configured Storage disk roots —
     * defensive guard against a future bug letting arbitrary paths into the
     * manifest reach FS operations.
     *
     * The string-level check is anchored at the disk root path emitted by the
     * Storage facade, without `realpath()`, so a regular file under a disk
     * root always passes even when the root itself sits behind a symlink. A
     * symlinked *file* is the exception: its target is resolved and must live
     * inside a disk root too, mirroring `ScanManifest::shouldSkip()`, so a
     * planted link cannot read outside the site at upload time.
     *
     * @param string $absolutePath The absolute path stored in the consolidated manifest.
     * @return string The same path, validated.
     * @throws \RuntimeException
     */
    public static function resolveSafePath($absolutePath)
    {
        if (!is_string($absolutePath)) {
            throw new \RuntimeException('Path is not a string');
        }

        // Reject `..` only as a path SEGMENT, not as a substring.
        // Otherwise legitimate filenames like `file..backup.zip` get rejected.
        $normalized = Str::replace('\\', '/', $absolutePath);
        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '..') {
                throw new \RuntimeException(sprintf('Path traversal rejected: %s', $absolutePath));
            }
        }

        if (Str::contains($absolutePath, "\0")) {
            throw new \RuntimeException(sprintf('Null byte in path rejected: %s', $absolutePath));
        }

        if (!self::isInsideDiskRoots($normalized, false)) {
            throw new \RuntimeException(sprintf('Path outside any known disk root: %s', $absolutePath));
        }

        if (is_link($absolutePath)) {
            $target = realpath($absolutePath);

            if ($target === false || !self::isInsideDiskRoots($target, true)) {
                throw new \RuntimeException(sprintf('Symlink target outside any known disk root: %s', $absolutePath));
            }
        }

        return $absolutePath;
    }

    /**
     * Whether `$path` is one of our disk roots or lives under one.
     *
     * @param string $path Absolute path. Pass a `realpath()` result together
     *                     with `$resolved = true` to compare against the
     *                     resolved roots (symlink targets); pass the manifest
     *                     string with `false` to compare against the roots as
     *                     the Storage facade emits them.
     * @param bool $resolved
     * @return bool
     */
    public static function isInsideDiskRoots($path, $resolved)
    {
        if (!is_string($path) || $path === '') {
            return false;
        }

        $normalized = rtrim(Str::replace('\\', '/', $path), '/');

        foreach (self::diskRoots($resolved) as $root) {
            if ($normalized === $root || Str::startsWith($normalized, $root . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param bool $resolved
     * @return string[] Normalised roots, without trailing slash, empty ones dropped.
     */
    private static function diskRoots($resolved)
    {
        $key = $resolved ? 'resolved' : 'raw';

        if (isset(self::$diskRoots[$key])) {
            return self::$diskRoots[$key];
        }

        $roots = [];

        foreach (self::$diskNames as $disk) {
            $root = Storage::disk($disk)->path('');

            if ($resolved) {
                $root = realpath($root);

                if ($root === false) {
                    continue;
                }
            }

            $root = rtrim(Str::replace('\\', '/', $root), '/');

            if ($root !== '') {
                $roots[] = $root;
            }
        }

        self::$diskRoots[$key] = array_values(array_unique($roots));

        return self::$diskRoots[$key];
    }
}
