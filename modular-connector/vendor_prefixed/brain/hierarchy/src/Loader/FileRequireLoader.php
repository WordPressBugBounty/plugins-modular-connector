<?php

/*
 * This file is part of the Hierarchy package.
 *
 * (c) Giuseppe Mazzapica <giuseppe.mazzapica@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Modular\ConnectorDependencies\Brain\Hierarchy\Loader;

/**
 * @author  Giuseppe Mazzapica <giuseppe.mazzapica@gmail.com>
 * @license http://opensource.org/licenses/MIT MIT
 * @internal
 */
final class FileRequireLoader implements TemplateLoaderInterface
{
    /**
     * {@inheritdoc}
     */
    public function load($templatePath)
    {
        $production = !\defined('WP_DEBUG') || !\WP_DEBUG;
        \ob_start();
        if (!$production) {
            /** @noinspection PhpIncludeInspection */
            require $templatePath;
        } elseif (\file_exists($templatePath)) {
            /** @noinspection PhpIncludeInspection */
            require $templatePath;
        }
        $content = \trim(\ob_get_clean());
        return $content;
    }
}
