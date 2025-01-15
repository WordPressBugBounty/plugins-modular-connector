<?php

namespace Modular\Connector\Backups\Iron\Helpers;

use Modular\Connector\Backups\Iron\BackupPart;
use Modular\ConnectorDependencies\Illuminate\Support\Collection;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Storage;
use Modular\ConnectorDependencies\Illuminate\Support\Str;
use Modular\ConnectorDependencies\Symfony\Component\Finder\Finder;
use function Modular\ConnectorDependencies\data_get;

class File
{
    public static $delimiter = ';';
    public static $enclosure = '"';
    public static $escape = '\\';

    /**
     * @param string $disk
     * @return array
     */
    public static function getDefaultExclusions(string $disk): array
    {
        $default = [
            BackupPart::INCLUDE_CORE => array_unique(
                array_merge(
                    [
                        '.idea',
                        '.wp-cli',
                        'error_log',

                        // WordPress default values
                        'wp-content', // WP_CONTENT_DIR
                        'wp-content/plugins', // WP_PLUGIN_DIR
                        'wp-content/themes', // INCLUDE_THEMES
                        'wp-content/mu-plugins', // WPMU_PLUGIN_DIR
                        'wp-content/uploads', // INCLUDE_UPLOADS,
                    ],
                    [
                        File::getRelativeDiskToDisk('backups', BackupPart::INCLUDE_CORE),
                        File::getRelativeDiskToDisk(BackupPart::INCLUDE_CONTENT, BackupPart::INCLUDE_CORE),
                        File::getRelativeDiskToDisk(BackupPart::INCLUDE_PLUGINS, BackupPart::INCLUDE_CORE),
                        File::getRelativeDiskToDisk(BackupPart::INCLUDE_THEMES, BackupPart::INCLUDE_CORE),
                        File::getRelativeDiskToDisk(BackupPart::INCLUDE_MU_PLUGINS, BackupPart::INCLUDE_CORE),
                        File::getRelativeDiskToDisk(BackupPart::INCLUDE_UPLOADS, BackupPart::INCLUDE_CORE),
                    ]
                ),
            ),
            BackupPart::INCLUDE_CONTENT => array_unique(
                array_merge(
                    [
                        'error_log',
                        'modular_storage',
                        'modular_backups',
                        'upgrade-temp-backup',
                        'error_log',
                        'cache',
                        'lscache',
                        'litepeed',
                        'et-cache',
                        'updraft',
                        'aiowps_backups',
                        'ai1wm-backups',
                        'backups-dup-pro',
                        'debug.log',

                        // WordPress default values
                        'uploads', // INCLUDE_UPLOADS
                        'plugins', // INCLUDE_PLUGINS
                        'themes', // INCLUDE_THEMES
                        'mu-plugins', // INCLUDE_MU_PLUGINS
                    ],
                    [
                        File::getRelativeDiskToDisk('backups', BackupPart::INCLUDE_CONTENT),
                        File::getRelativeDiskToDisk(BackupPart::INCLUDE_UPLOADS, BackupPart::INCLUDE_CONTENT),
                        File::getRelativeDiskToDisk(BackupPart::INCLUDE_PLUGINS, BackupPart::INCLUDE_CONTENT),
                        File::getRelativeDiskToDisk(BackupPart::INCLUDE_THEMES, BackupPart::INCLUDE_CONTENT),
                        File::getRelativeDiskToDisk(BackupPart::INCLUDE_MU_PLUGINS, BackupPart::INCLUDE_CONTENT),
                        File::getRelativeDiskToDisk(BackupPart::INCLUDE_UPLOADS, BackupPart::INCLUDE_CONTENT),
                    ]
                ),
            ),

            BackupPart::INCLUDE_PLUGINS => [
                'modular-connector/svn',
            ],

            BackupPart::INCLUDE_THEMES => [],

            BackupPart::INCLUDE_MU_PLUGINS => [],

            BackupPart::INCLUDE_UPLOADS => [],
        ];

        return data_get($default, $disk, []);
    }

    /**
     * @param string $disk
     * @param string $path
     * @param Collection $excluded
     * @return Finder
     */
    public static function finder(string $disk, string $path, Collection $excluded): Finder
    {
        return (new Finder())
            ->followLinks()
            ->ignoreDotFiles(false)
            ->ignoreUnreadableDirs()
            ->ignoreVCS(true)
            ->filter(
                fn(\SplFileInfo $file) => file_exists($file->getRealPath()) &&
                    $file->isReadable() &&
                    !File::shouldExclude($disk, $file, $excluded)
            )
            ->in($path);
    }

    /**
     * @param string $disk
     * @param Collection $excluded
     * @param $offset
     * @param $limit
     * @return \LimitIterator|\Symfony\Component\Finder\Finder
     */
    public static function finderWithLimitter(string $disk, Collection $excluded, $offset, $limit)
    {
        $path = Storage::disk($disk)->path('');
        $finder = static::finder($disk, $path, $excluded);

        return new \LimitIterator($finder->getIterator(), $offset, $limit);
    }

    /**
     * @param string $disk
     * @param \SplFileInfo $file
     * @param Collection $excluded
     * @return bool
     */
    public static function shouldExclude(string $disk, \SplFileInfo $file, Collection $excluded): bool
    {
        $abspath = Storage::disk($disk)->path('');
        $path = Str::replaceFirst($abspath, '', $file->getPathname());

        return $excluded->some(fn($excludeItem) => Str::startsWith($path, $excludeItem));
    }

    /**
     * Returns an object representing the provided folder structure as an object in which keys with content value
     * represent folders and keys with 'null' content value represent files.
     *
     * @param string $disk
     * @param string $path
     * @param bool $withExclusion
     * @return Collection
     */
    public static function getTree(string $disk, string $path, bool $withExclusion): Collection
    {
        $excluded = [];

        if ($withExclusion) {
            $excluded = static::getDefaultExclusions($disk);
        }

        $files = static::finder($disk, $path, Collection::make($excluded))
            ->in($path)
            ->sortByType()
            ->depth('== 0');

        return Collection::make($files)
            ->map(fn($item) => static::mapItem($item, $disk, true))
            ->values();
    }

    /**
     * @param string $disk
     * @param string $relativeDisk
     * @return string
     */
    public static function getRelativeDiskToDisk(string $disk, string $relativeDisk): string
    {
        $disk = Storage::disk($disk)->path('');

        return static::getRelativePathToDisk($disk, $relativeDisk);
    }

    /**
     * @param string $path
     * @param string $relativeDisk
     * @return string
     */
    public static function getRelativePathToDisk(string $path, string $relativeDisk): string
    {
        $relativeDisk = Storage::disk($relativeDisk)->path('');

        return untrailingslashit(Str::after($path, $relativeDisk));
    }

    /**
     * @param \SplFileInfo $item
     * @param string $disk
     * @param bool $withParentPath
     * @return array
     */
    public static function mapItem(\SplFileInfo $item, string $disk, bool $withParentPath = false): array
    {
        $type = $item->getType();

        if ($item->isLink()) {
            $type = is_dir($item->getRealPath()) ? 'dir' : $type;
        }

        $relativePath = File::getRelativePathToDisk($item, $disk);

        $data = [
            'checksum' => $type !== 'dir' ? hash_file('sha256', $item->getRealPath()) : null,
            'type' => Str::substr($type, 0, 1),
            'size' => $item->getSize(),
            'timestamp' => $item->getMTime(),
            'path' => $relativePath,
        ];

        if ($withParentPath) {
            $data['parent_path'] = File::getRelativePathToDisk($item->getPath(), $disk);
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
