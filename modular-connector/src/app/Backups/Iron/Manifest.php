<?php

namespace Modular\Connector\Backups;

use Illuminate\Support\Facades\Storage;
use Modular\Connector\Services\Helpers\File;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

class Manifest
{
    /**
     * @var string
     */
    protected string $root;

    /**
     * @var BackupOptions
     */
    protected BackupOptions $options;

    /**
     * @var int
     */
    protected int $maxDepth;

    /**
     * @var bool
     */
    public bool $includeHeaders = false;

    /**
     * @var string[]
     */
    protected $indexHeaders = [
        'parent_path_hash',
        'parent_path',
        'offset',
        'limit',
        'depth',
    ];

    /**
     * @var string[]
     */
    protected $manifestHeaders = [
        'parent_path_hash',
        'path',
        'realpath',
        'type',
        'checksum',
        'symlink_target',
        'timestamp',
        'size',
        'depth'
    ];

    /**
     * @param BackupOptions $options
     * @param int $maxDepth
     */
    public function __construct(BackupOptions $options, int $maxDepth = 0)
    {
        $this->root = $options->root;
        $this->options = $options;
        $this->maxDepth = $maxDepth;
    }

    /**
     * @return static
     */
    public static function getInstance(BackupOptions $options, int $maxDepth = 0)
    {
        return new static($options, $maxDepth);
    }

    public function getItem(BackupPart $part)
    {
        $manifestPath = $this->getPath($part->type, false);

        return File::readRange(Storage::path($manifestPath), $this->manifestHeaders, $part->offset);
    }

    /**
     * @param BackupPart $part
     * @param bool $useMain
     * @param array $root
     * @return false|array
     */
    public function scanDir(BackupPart $part, bool $useMain, array $root = [])
    {
        return $useMain ? $this->addItemsFromMainManifest($part, $root) : $this->addItemsFromIterator($part, $root);
    }

    /**
     * @param BackupPart $part
     * @param array $root
     * @return array
     */
    public function searchItemInMainManifest(BackupPart $part, array $root): array
    {
        $manifestMainIndexPath = $this->getPath($part->type, true, 'index');

        if (!Storage::exists($manifestMainIndexPath)) {
            return [];
        }

        if (empty($root)) {
            return File::readRange(Storage::path($manifestMainIndexPath), $this->indexHeaders, 0);
        }

        $pathHash = File::calculatePathHash($root['path']);

        return File::searchIn($manifestMainIndexPath, $this->indexHeaders, $pathHash);
    }

    /**
     * @param BackupPart $part
     * @param array $root
     * @return false|array
     */
    public function addItemsFromMainManifest(BackupPart $part, array $root)
    {
        $mainIndex = $this->searchItemInMainManifest($part, $root);

        if (!isset($mainIndex['offset'])) {
            return false;
        }

        $manifestPath = $this->getPath($part->type, true);
        $offset = intval($mainIndex['offset']);
        $limit = intval($mainIndex['limit']);

        $tree = File::readRange(Storage::path($manifestPath), $this->manifestHeaders, $offset, $limit);

        $manifestPath = $this->getPath($part->type, false);

        $index = $mainIndex;
        $index['offset'] = File::totalLines(Storage::path($manifestPath));
        $index['limit'] = $index['offset'] + count($tree) - 1;

        return [$tree, $index];
    }

    /**
     * @param BackupPart $part
     * @param array $root
     * @return array
     */
    public function addItemsFromIterator(BackupPart $part, array $root)
    {
        $finder = File::getFinder($root['realpath'], $this->options->excludedFiles);

        $tree = [];
        $index = [];

        $newDepth = $root['depth'] + 1;
        $this->scanDirectory($finder, $tree, $index, $newDepth, $newDepth);

        $index = $index[array_key_first($index)] ?? false;

        if (!$index) {
            return $index;
        }

        $manifestPath = $this->getPath($part->type, false);

        $index['offset'] = File::totalLines(Storage::path($manifestPath));
        $index['limit'] = $index['offset'] + count($tree) - 1;

        return [$tree, $index];
    }

    /**
     * @param string $type
     * @param bool $isManin
     * @return void
     */
    public function make(string $type, bool $isManin): int
    {
        $finder = File::getFinder($this->root, $this->options->excludedFiles);

        $tree = [];
        $index = [];

        $this->scanDirectory($finder, $tree, $index, $this->maxDepth);

        $this->saveManifest($type, $tree, $isManin);
        $this->saveManifestIndex($type, $index, $isManin);

        return count($index);
    }

    /**
     * Export files to file
     * @param string $type
     * @param array $tree
     * @param bool $isMain
     * @return void
     */
    public function saveManifest(string $type, array $tree, bool $isMain)
    {
        $manifestPath = $this->getPath($type, $isMain);

        if ($this->includeHeaders) {
            Storage::put($manifestPath, implode(File::$delimiter, array_keys($tree[0])));
        }

        Storage::append(
            $manifestPath,
            implode(
                "\n",
                array_map(
                    fn($item) => implode(
                        File::$delimiter,
                        array_values(array_map(fn($value) => is_string($value) ? sprintf('"%s"', $value) : $value, $item))
                    ),
                    $tree
                )
            )
        );
    }

    /**
     * Export index to file
     *
     * @param string $type
     * @param array $index
     * @param bool $isMain
     * @return void
     */
    public function saveManifestIndex(string $type, array $index, bool $isMain)
    {
        $manifestPath = $this->getPath($type, $isMain, 'index');

        if ($this->includeHeaders) {
            Storage::put($manifestPath, implode(File::$delimiter, array_keys(array_values($index)[0])));
        }

        Storage::append(
            $manifestPath,
            implode(
                "\n",
                array_map(
                    fn($item) => implode(
                        File::$delimiter,
                        array_values(array_map(fn($value) => is_string($value) ? sprintf('"%s"', $value) : $value, $item))
                    ),
                    $index
                )
            )
        );
    }

    /**
     * @param string $name
     * @param bool $isMain
     * @param string $suffix
     * @return string
     */
    public function getPath(string $name, bool $isMain, string $suffix = ''): string
    {
        $name = sprintf('%s-manifest', $name, $this->options->batch);

        if ($isMain) {
            $name = sprintf('%s-main', $name);
        }

        if ($suffix) {
            $name = sprintf('%s-%s', $name, $suffix);
        }

        return Backup::path(sprintf('%s/%s', $this->options->name, $name));
    }

    /**
     * Scan directory
     *
     * @param Finder $finder
     * @param array $tree
     * @param array $index
     * @param int $maxDepth
     * @param int $currentDepth
     */
    public function scanDirectory(Finder $finder, array &$tree, array &$index, int $maxDepth, int $currentDepth = 0)
    {
        // Search files in the current directory
        foreach ($finder->files()->depth(0) as $item) {
            [$tree, $index] = $this->exportToManifest($item, $tree, $index, $currentDepth);
        }

        // Search directories in the current directory
        foreach ($finder->directories()->depth(0) as $dir) {
            [$tree, $index] = $this->exportToManifest($dir, $tree, $index, $currentDepth);
        }

        // Check if we reached the max depth
        if ($currentDepth >= $maxDepth) {
            return;
        }

        // Search files in the subdirectory
        foreach ($finder->directories()->depth(0) as $dir) {
            $subFinder = File::getFinder($dir->getPathname(), $this->options->excludedFiles);

            $this->scanDirectory($subFinder, $tree, $index, $maxDepth, $currentDepth + 1);
        }
    }

    /**
     * Export item to main manifest
     *
     * @param SplFileInfo $item
     * @param array $tree
     * @param array $index
     * @param int $depth
     * @return array
     */
    public function exportToManifest(SplFileInfo $item, array $tree, array $index, int $depth)
    {
        $sumHeader = $this->includeHeaders ? 1 : 0;

        // Calculate offset
        $offset = count($tree) + $sumHeader;

        // Map item to array
        $item = File::mapItem($item);
        $item['depth'] = $depth;

        $parentPath = $item['parent_path'];
        unset($item['parent_path']);

        $tree[] = $item;

        // Add index
        if (!isset($index[$item['parent_path_hash']])) {
            $index[$item['parent_path_hash']] = [
                'parent_path_hash' => $item['parent_path_hash'],
                'parent_path' => $parentPath,
                'offset' => $offset,
                'limit' => 0, // We set this value later
                'depth' => $depth,
            ];
        }

        $index[$item['parent_path_hash']]['limit'] = count($tree) - 1 + $sumHeader;

        return [$tree, $index];
    }
}
