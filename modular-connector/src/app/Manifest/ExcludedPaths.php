<?php

namespace Modular\Connector\Manifest;

use Modular\Connector\Backups\Iron\Helpers\File;
use Modular\ConnectorDependencies\Illuminate\Support\Str;

/**
 * Maps the `excluded` path lists of a backup or scan payload onto one disk.
 *
 * Both drivers receive the same two shapes from the API and both hand the
 * result to the same per-file matcher (`File::shouldExclude()`, run by
 * `AbstractManifest`), so the translation lives in one place:
 *
 *   - Root-relative entries (`excluded.files` for backups, `excluded.core`
 *     for scans): paths from the WordPress root such as
 *     `wp-content/plugins/hello-dolly`. Each disk keeps the entries that live
 *     under it, rewritten relative to the disk (`hello-dolly`), and an entry
 *     that is the disk itself or one of its parents (`wp-content`) becomes
 *     `''`, which the parts read as "the whole disk is excluded".
 *   - Disk-relative entries (`excluded.{plugins,themes,...}` with a custom
 *     filesystem): already relative to the disk (`hello-dolly`). A
 *     root-relative spelling of the same thing (`wp-content/plugins/hello-dolly`)
 *     is accepted too and stripped; the disk itself becomes `''`.
 *
 * Entries are compared on whole path segments (`wp-c` is not a parent of
 * `wp-content`) after normalising separators, so a stray trailing slash or a
 * backslash cannot silently disable an exclusion.
 */
final class ExcludedPaths
{
    /**
     * Default exclusions for the disk plus both payload sources, deduplicated.
     *
     * @param string $type Disk type (core, plugins, themes, mu_plugins, content, uploads).
     * @param array $rootRelative Entries relative to the WordPress root.
     * @param array $diskRelative Entries relative to this disk (root-relative accepted).
     * @return string[] Disk-relative entries; `''` marks the whole disk as excluded.
     */
    public static function forDisk($type, array $rootRelative, array $diskRelative = [])
    {
        $merged = array_merge(
            File::getDefaultFileExclusions($type),
            File::getDefaultDirectoriesExclusions($type),
            self::rootRelativeToDisk($type, $rootRelative),
            self::diskRelative($type, $diskRelative)
        );

        return array_values(array_unique($merged));
    }

    /**
     * Project root-relative entries onto the disk. Entries that live elsewhere
     * are dropped (their own disk picks them up).
     *
     * @param string $type
     * @param array $entries
     * @return string[]
     */
    public static function rootRelativeToDisk($type, array $entries)
    {
        $diskPath = self::diskPath($type);
        $mapped = [];

        foreach (self::normalize($entries) as $entry) {
            // The disk itself or a parent of it → whole disk excluded.
            if (self::isSameOrUnder($diskPath, $entry)) {
                $mapped[] = '';

                continue;
            }

            if (self::isSameOrUnder($entry, $diskPath)) {
                $mapped[] = trim(Str::after($entry, $diskPath), '/');
            }
        }

        return $mapped;
    }

    /**
     * Normalise per-disk entries, accepting the root-relative spelling as well.
     *
     * @param string $type
     * @param array $entries
     * @return string[]
     */
    public static function diskRelative($type, array $entries)
    {
        $diskPath = self::diskPath($type);
        $mapped = [];

        foreach (self::normalize($entries) as $entry) {
            if ($diskPath !== '' && self::isSameOrUnder($entry, $diskPath)) {
                $mapped[] = trim(Str::after($entry, $diskPath), '/');

                continue;
            }

            $mapped[] = $entry;
        }

        return $mapped;
    }

    /**
     * Canonical form of the entries the API sends: forward slashes, no
     * surrounding separators or whitespace, non-string and empty entries
     * dropped. Entries are plain path prefixes (a directory or a file);
     * globs are not supported by either matcher.
     *
     * @param array $entries
     * @return string[]
     */
    public static function normalize(array $entries)
    {
        $normalized = [];

        foreach ($entries as $entry) {
            if (!is_string($entry)) {
                continue;
            }

            $entry = trim(Str::replace('\\', '/', $entry));
            $entry = trim($entry, '/');

            if ($entry === '') {
                continue;
            }

            $normalized[] = $entry;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * Whether `$path` equals `$base` or lives under it, comparing whole path
     * segments. An empty base is the root and contains everything.
     *
     * @param string $path
     * @param string $base
     * @return bool
     */
    public static function isSameOrUnder($path, $base)
    {
        if ($base === '') {
            return true;
        }

        return $path === $base || Str::startsWith($path, $base . '/');
    }

    /**
     * Path of the disk relative to the WordPress root (`wp-content/plugins`),
     * `''` for core.
     *
     * @param string $type
     * @return string
     */
    private static function diskPath($type)
    {
        return trim(Str::replace('\\', '/', (string)File::getRelativeDiskToDisk($type, 'core')), '/');
    }
}
