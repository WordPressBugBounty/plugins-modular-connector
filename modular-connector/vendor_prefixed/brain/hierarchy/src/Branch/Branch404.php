<?php

/*
 * This file is part of the Hierarchy package.
 *
 * (c) Giuseppe Mazzapica <giuseppe.mazzapica@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Modular\ConnectorDependencies\Brain\Hierarchy\Branch;

/**
 * @author  Giuseppe Mazzapica <giuseppe.mazzapica@gmail.com>
 * @license http://opensource.org/licenses/MIT MIT
 * @internal
 */
final class Branch404 implements BranchInterface
{
    /**
     * {@inheritdoc}
     */
    public function name()
    {
        return '404';
    }
    /**
     * {@inheritdoc}
     */
    public function is(\WP_Query $query)
    {
        return $query->is_404();
    }
    /**
     * {@inheritdoc}
     */
    public function leaves(\WP_Query $query)
    {
        return ['404'];
    }
}
