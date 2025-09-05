<?php

namespace Modular\Connector\Backups\Iron\Helpers;

use Modular\Connector\Backups\Iron\BackupPart;
use Modular\ConnectorDependencies\Illuminate\Support\Collection;
use Modular\ConnectorDependencies\Illuminate\Support\Facades\Log;
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
    public static function getDefaultFileExclusions(string $disk): array
    {
        $default = [
            BackupPart::INCLUDE_CORE => [
                '.idea',
                '.wp-cli',
                'error_log',
            ],
            BackupPart::INCLUDE_CONTENT =>
                [
                    'error_log',
                    'debug.log',
                    'mysql.sql',
                ],
            BackupPart::INCLUDE_PLUGINS => [],

            BackupPart::INCLUDE_THEMES => [],

            BackupPart::INCLUDE_MU_PLUGINS => [],

            BackupPart::INCLUDE_UPLOADS => [],
        ];

        return data_get($default, $disk, []);
    }

    /**
     * @param string $disk
     * @return array
     */
    public static function getDefaultDirectoriesExclusions(string $disk): array
    {
        $default = [
            BackupPart::INCLUDE_CORE => array_unique(
                array_merge(
                    [
                        '.opcache',
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
                        'modular_storage',
                        'modular_backups',
                        'upgrade-temp-backup',
                        'cache',
                        'lscache',
                        'litepeed',
                        'et-cache',
                        'updraft',
                        'wpvividbackups',
                        'aiowps_backups',
                        'ai1wm-backups',
                        'backups-dup-pro',

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
     * @param bool $excludeDefault
     * @return Finder
     */
    public static function finder(string $disk, string $path, bool $excludeDefault = true): Finder
    {
        $defaultExclusions = $excludeDefault ? static::getDefaultDirectoriesExclusions($disk) : [];

        $defaultExclusions = array_map(
            fn($item) => sprintf('#^%s(/|$)#', preg_quote($item, '#')),
            $defaultExclusions
        );

        return (new Finder())
            ->followLinks()
            ->ignoreDotFiles(false)
            ->ignoreUnreadableDirs()
            ->ignoreVCS(true)
            ->in($path)
            ->notPath($defaultExclusions);
    }

    /**
     * @param string $disk
     * @param Collection $excluded
     * @param $offset
     * @param $limit
     * @return \LimitIterator|\Symfony\Component\Finder\Finder
     */
    public static function finderWithLimitter(string $disk, $offset, $limit)
    {
        $path = Storage::disk($disk)->path('');
        $finder = static::finder($disk, $path);

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
        if (!file_exists($file->getRealPath()) || !$file->isReadable()) {
            return true;
        }

        $abspath = Storage::disk($disk)->path('');
        $path = Str::replaceFirst($abspath, '', $file->getPathname());
        $dirname = dirname($path);

        return $excluded->some(fn($excludeItem) => Str::startsWith($dirname, $excludeItem) || $path === $excludeItem);
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
        $excluded = $withExclusion ? static::getDefaultDirectoriesExclusions($disk) : [];

        $files = static::finder($disk, $path, $withExclusion)
            ->filter(fn(\SplFileInfo $file) => !self::shouldExclude($disk, $file, Collection::make($excluded)))
            ->depth('== 0')
            ->sortByType();

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
            Log::error($e);

            $closed = false;
        }

        if (!$closed) {
            throw new \ErrorException($zip->getStatusString());
        }

        return $closed;
    }

    /**
     * @param $dir
     * @return bool
     */
    public static function deleteDirectory($dir)
    {
        if (!file_exists($dir)) {
            return true;
        }

        if (!is_dir($dir)) {
            return unlink($dir);
        }

        foreach (scandir($dir) as $item) {
            if ($item == '.' || $item == '..') {
                continue;
            }

            if (!self::deleteDirectory($dir . DIRECTORY_SEPARATOR . $item)) {
                return false;
            }
        }

        return rmdir($dir);
    }

    /**
     * Calculate compression ratio based on file type
     *
     * @param array $file File information array with 'path' key
     * @return float Compression ratio (0.0 to 1.0)
     */
    public static function getCompressionRatio(array $file): float
    {
        if ($file['type'] === 'd') {
            return 1.0; // Directories are not compressed
        }

        $extension = Str::lower(pathinfo($file['path'], PATHINFO_EXTENSION));

        // Already compressed files (images, videos, archives) - minimal compression expected
        $minimalCompression = [
            // Images
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff', 'ico',
            // Videos
            'mp4', 'avi', 'mov', 'wmv', 'flv', 'webm', 'mkv', '3gp',
            // Audio
            'mp3', 'aac', 'ogg', 'wma', 'flac', 'wav',
            // Archives
            'zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'xz',
            // Documents (already compressed)
            'pdf', 'docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp',
        ];

        // Text-based files - good compression expected
        $goodCompression = [
            // Web files
            'html', 'htm', 'css', 'js', 'json', 'xml', 'svg',
            // Programming files
            'php', 'py', 'rb', 'java', 'c', 'cpp', 'h', 'hpp',
            'go', 'rs', 'swift', 'kt', 'ts', 'jsx', 'tsx', 'vue',
            // Text files
            'txt', 'md', 'rst', 'log', 'csv', 'tsv',
            // Config files
            'ini', 'conf', 'cfg', 'yml', 'yaml', 'toml',
        ];

        // Database and logs - excellent compression expected
        $excellentCompression = [
            'sql', 'dump', 'log', 'out', 'err',
        ];

        if (in_array($extension, $excellentCompression)) {
            return 0.65; // 65% of original size (35% compression)
        }

        if (in_array($extension, $goodCompression)) {
            return 0.75; // 75% of original size (25% compression)
        }

        if (in_array($extension, $minimalCompression)) {
            return 0.97; // 97% of original size (3% compression)
        }

        // Default for unknown/binary files
        return 0.90; // 90% of original size (10% compression)
    }
}
