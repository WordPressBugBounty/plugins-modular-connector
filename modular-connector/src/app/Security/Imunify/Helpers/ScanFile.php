<?php

namespace Modular\Connector\Security\Imunify\Helpers;

use Modular\Connector\Backups\Iron\Helpers\File;
use Modular\Connector\Backups\Iron\Helpers\ManifestHasher;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Storage;
use Modular\ConnectorDependencies\Illuminate\Support\Str;
use Modular\ConnectorDependencies\Symfony\Component\Finder\Finder;

class ScanFile
{
    /**
     * @var array
     */
    public static $defaultDirectoryExclusions = [
        '.git',
        'node_modules',
        '.opcache',
    ];

    /**
     * @param \SplFileInfo $file
     * @param array $allowedExtensions
     * @param int $maxFileSize
     * @param array $excludedExtensions
     * @return bool
     */
    public static function shouldInclude(\SplFileInfo $file, array $allowedExtensions, $maxFileSize, array $excludedExtensions = [])
    {
        if (!$file->isFile()) {
            return false;
        }

        if (!file_exists($file->getRealPath()) || !$file->isReadable()) {
            return false;
        }

        $extension = Str::lower($file->getExtension());

        if (!in_array($extension, $allowedExtensions)) {
            return false;
        }

        if (!empty($excludedExtensions) && in_array($extension, $excludedExtensions, true)) {
            return false;
        }

        if ($maxFileSize > 0 && $file->getSize() > $maxFileSize) {
            return false;
        }

        return true;
    }

    /**
     * @param string $disk
     * @param array $additionalExclusions
     * @return Finder
     */
    public static function finder($disk, array $additionalExclusions = [])
    {
        $path = Storage::disk($disk)->path('');

        $exclusions = array_merge(static::$defaultDirectoryExclusions, $additionalExclusions);

        $exclusions = array_map(
            fn($item) => sprintf('#^%s(/|$)#', preg_quote($item, '#')),
            array_unique($exclusions)
        );

        return (new Finder())
            ->ignoreDotFiles(false)
            ->ignoreUnreadableDirs()
            ->ignoreVCS(true)
            ->in($path)
            ->notPath($exclusions);
    }

    /**
     * @param string $disk
     * @param int $offset
     * @param int $limit
     * @param array $additionalExclusions
     * @return \LimitIterator
     */
    public static function finderWithLimiter($disk, $offset, $limit, array $additionalExclusions = [])
    {
        $finder = static::finder($disk, $additionalExclusions);

        return new \LimitIterator($finder->getIterator(), $offset, $limit);
    }

    /**
     * Map a file to a manifest row. Returns `null` when the file cannot be hashed
     * (unreadable, vanished mid-scan, etc.) — callers MUST drop these rows so we
     * don't register paths with Imunify whose hash we will never deliver. That
     * would leave the file in `needs_sha256` state on Imunify's side forever and
     * inflate `/scan/start`'s file count vs the actual `/hashes` submissions.
     *
     * @param \SplFileInfo $file
     * @param string $disk
     * @param ManifestHasher $hasher
     * @return array|null
     */
    public static function mapItem(\SplFileInfo $file, $disk, ManifestHasher $hasher)
    {
        $relativePath = File::getRelativePathToDisk($file, $disk);
        $checksum = $hasher->computeHash($file);

        if ($checksum === null || $checksum === '') {
            return null;
        }

        return [
            'checksum' => $checksum,
            'type' => 'f',
            'size' => $file->getSize(),
            'timestamp' => $file->getMTime(),
            'path' => $relativePath,
        ];
    }
}
