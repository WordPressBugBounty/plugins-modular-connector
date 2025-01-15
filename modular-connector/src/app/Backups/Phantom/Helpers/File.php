<?php

namespace Modular\Connector\Backups\Phantom\Helpers;

use Modular\ConnectorDependencies\Illuminate\Support\Collection;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Storage;
use Modular\ConnectorDependencies\Illuminate\Support\Str;
use Modular\ConnectorDependencies\Symfony\Component\Finder\Finder;
use Modular\ConnectorDependencies\Symfony\Component\Finder\SplFileInfo;

class File
{
    public static $delimiter = ';';
    public static $enclosure = '"';
    public static $escape = '\\';

    /**
     * @param string $path
     * @param array $excluded
     * @return Finder
     */
    public static function getFinder(string $path, array $excluded): Finder
    {
        $finder = new Finder();

        $relativePath = Str::replaceFirst(ABSPATH, '', $path);

        $excluded = Collection::make($excluded)
            ->map(fn($item) => ltrim(Str::replaceFirst($relativePath, '', $item), '/'))
            ->toArray();

        return $finder
            ->followLinks()
            ->ignoreDotFiles(false)
            ->ignoreUnreadableDirs()
            ->ignoreVCS(true)
            ->in($path)
            ->exclude($excluded)
            ->filter(
                fn(\SplFileInfo $file) => file_exists($file->getRealPath()) &&
                    $file->isReadable() &&
                    !static::checkIsExcluded($file, $excluded)
            );
    }

    /**
     * Check if a file has been marked as excluded
     *
     * @param SplFileInfo $item
     * @param array $excluded
     * @return bool
     */
    public static function checkIsExcluded(SplFileInfo $item, array $excluded)
    {
        $excluded = Collection::make($excluded);

        return $excluded->some(fn($excludeItem) => Str::startsWith($item->getPathname(), Storage::disk('core')->path($excludeItem)));
    }

    /**
     * @param string $path
     * @return string
     */
    public static function calculatePathHash(string $path)
    {
        $path = rtrim($path, '/');

        return hash('sha1', $path);
    }

    /**
     * @param SplFileInfo $item
     * @return array
     */
    public static function mapItem(SplFileInfo $item)
    {
        /**
         * @var \SplFileInfo $item
         */
        $type = $item->getType();

        if ($item->isLink()) {
            $type = is_dir($item->getRealPath()) ? 'dir' : $type;
        }

        $name = $item->getBasename();
        $parentPath = str_ireplace([untrailingslashit(ABSPATH), $name], '', $item->getPathname());
        $relativePath = str_ireplace(untrailingslashit(ABSPATH), '', $item->getPathname());

        return [
            'parent_path_hash' => self::calculatePathHash($parentPath),
            'parent_path' => $parentPath,
            'path' => $relativePath,
            'realpath' => $item->getRealPath() ?? $item->getPathname(),
            'type' => Str::substr($type, 0, 1),
            'checksum' => $type !== 'dir' ? hash_file('sha256', $item->getRealPath()) : null,
            'symlink_target' => $item->isLink() ? $item->getLinkTarget() : null,
            'timestamp' => $item->getMTime(),
            'size' => $item->getSize(),
        ];
    }

    /**
     * @param string $filePath
     * @return int
     */
    public static function totalLines(string $filePath)
    {
        if (!file_exists($filePath)) {
            return 0;
        }

        $file = new \SplFileObject($filePath, 'r');
        $file->seek(PHP_INT_MAX);

        return $file->key() + 1;
    }

    /**
     * @param string $filePath
     * @param array $headers
     * @param $search
     * @return array
     */
    public static function searchIn(string $filePath, array $headers, $search)
    {
        $file = new \SplFileObject(Storage::path($filePath));
        $file->setFlags(\SplFileObject::READ_CSV | \SplFileObject::SKIP_EMPTY);
        $file->setCsvControl(self::$delimiter, self::$enclosure, self::$escape);

        while (!$file->eof()) {
            $row = $file->fgetcsv();

            if ($row && isset($row[0]) && $row[0] === $search) {
                return array_combine($headers, $row);
            }
        }

        return [];
    }

    /**
     * @param string $filePath
     * @param array $headers
     * @param int $start
     * @param int|null $end
     * @return array
     */
    public static function readRange(string $filePath, array $headers, int $start, int $end = null)
    {
        $data = [];
        $file = new \SplFileObject($filePath);
        $file->setFlags(\SplFileObject::READ_CSV | \SplFileObject::SKIP_EMPTY);
        $file->setCsvControl(self::$delimiter, self::$enclosure, self::$escape);

        // Got to the start of the range
        $file->seek($start);

        // If no end is provided, return the first row
        if ($end === null) {
            $row = $file->current();

            if ($row && count($row) > 1) {
                return array_combine($headers, $row);
            }

            return $data;
        }

        for ($currentLine = $start; $currentLine <= $end && !$file->eof(); $currentLine++) {
            $row = $file->current();

            if ($row && count($row) > 1) {
                $data[] = array_combine($headers, $row);
            }

            $file->next();
        }

        return $data;
    }

    /**
     * Open a zip archive
     *
     * @param string $path
     * @return \ZipArchive
     * @throws \ErrorException
     */
    public static function openZip(string $path): \ZipArchive
    {
        $zip = new \ZipArchive();

        if (!file_exists($path)) {
            $opened = $zip->open($path, \ZipArchive::CREATE);
        } else {
            $opened = $zip->open($path);
        }

        if ($opened !== true) {
            throw new \ErrorException($zip->getStatusString());
        }

        return $zip;
    }

    /**
     * Add files to given zip
     *
     * @param \ZipArchive $zip
     * @param array $item
     * @return void
     */
    public static function addToZip(\ZipArchive $zip, array $item): void
    {
        if ($item['type'] === 'd') {
            $zip->addEmptyDir(ltrim($item['path'], DIRECTORY_SEPARATOR));
        } else {
            $zip->addFile($item['realpath'], ltrim($item['path'], DIRECTORY_SEPARATOR));
        }
    }

    /**
     * Close ZipArchive
     *
     * @param \ZipArchive $zip
     * @return bool
     * @throws \ErrorException
     */
    public static function closeZip(\ZipArchive $zip): bool
    {
        try {
            $closed = $zip->close();
        } catch (\Throwable $e) {
            $closed = false;
        }

        if (!$closed) {
            throw new \ErrorException($zip->getStatusString());
        }

        return $closed;
    }
}
