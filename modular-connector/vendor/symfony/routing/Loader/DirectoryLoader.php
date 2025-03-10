<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Modular\ConnectorDependencies\Symfony\Component\Routing\Loader;

use Modular\ConnectorDependencies\Symfony\Component\Config\Loader\FileLoader;
use Modular\ConnectorDependencies\Symfony\Component\Config\Resource\DirectoryResource;
use Modular\ConnectorDependencies\Symfony\Component\Routing\RouteCollection;
class DirectoryLoader extends FileLoader
{
    /**
     * {@inheritdoc}
     */
    public function load($file, ?string $type = null)
    {
        $path = $this->locator->locate($file);
        $collection = new RouteCollection();
        $collection->addResource(new DirectoryResource($path));
        foreach (scandir($path) as $dir) {
            if ('.' !== $dir[0]) {
                $this->setCurrentDir($path);
                $subPath = $path . '/' . $dir;
                $subType = null;
                if (is_dir($subPath)) {
                    $subPath .= '/';
                    $subType = 'directory';
                }
                $subCollection = $this->import($subPath, $subType, \false, $path);
                $collection->addCollection($subCollection);
            }
        }
        return $collection;
    }
    /**
     * {@inheritdoc}
     */
    public function supports($resource, ?string $type = null)
    {
        // only when type is forced to directory, not to conflict with AnnotationLoader
        return 'directory' === $type;
    }
}
