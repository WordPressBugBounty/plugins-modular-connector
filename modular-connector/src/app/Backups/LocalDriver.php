<?php

namespace Modular\Connector\Backups;

use Modular\ConnectorDependencies\League\Flysystem\Adapter\Local;
use Modular\ConnectorDependencies\League\Flysystem\NotSupportedException;

/**
 * Local adapter that safely handles symlinks pointing outside the root directory.
 *
 * This adapter extends the functionality to handle cases where:
 * - The root path is a symlink to another location
 * - is_link() or is_dir() calls fail due to open_basedir restrictions
 */
class LocalDriver extends Local
{
    /**
     * @var int
     */
    protected $linkHandling;

    /**
     * Constructor.
     *
     * @param string $root
     * @param int $writeFlags
     * @param int $linkHandling
     * @param array $permissions
     *
     * @throws \LogicException
     */
    public function __construct($root, $writeFlags = LOCK_EX, $linkHandling = self::DISALLOW_LINKS, array $permissions = [])
    {
        try {
            $root = is_link($root) ? realpath($root) : $root;
        } catch (\Throwable $e) {
            // In some servers, is_link() may fail due to open_basedir restrictions.
        }

        $this->permissionMap = array_replace_recursive(static::$permissions, $permissions);

        try {
            $this->ensureDirectory($root);
        } catch (\Throwable $e) {
            // In some servers, mkdir() may fail due to open_basedir restrictions.
        }

        if (!is_dir($root) || !is_readable($root)) {
            throw new \LogicException('The root path ' . $root . ' is not readable.');
        }

        $this->setPathPrefix($root);
        $this->writeFlags = $writeFlags;
        $this->linkHandling = $linkHandling;
    }

    /**
     * Normalize the file info.
     *
     * @param \SplFileInfo $file
     *
     * @return array|void
     *
     * @throws NotSupportedException
     */
    protected function normalizeFileInfo(\SplFileInfo $file)
    {
        if (!$file->isLink()) {
            return $this->mapFileInfo($file);
        }

        if ($this->linkHandling & self::DISALLOW_LINKS) {
            throw NotSupportedException::forLink($file);
        }
    }
}
