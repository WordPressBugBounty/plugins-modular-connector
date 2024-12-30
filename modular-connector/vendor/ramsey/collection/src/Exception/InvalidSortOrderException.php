<?php

/**
 * This file is part of the ramsey/collection library
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @copyright Copyright (c) Ben Ramsey <ben@benramsey.com>
 * @license http://opensource.org/licenses/MIT MIT
 */
declare (strict_types=1);
namespace Modular\ConnectorDependencies\Ramsey\Collection\Exception;

use RuntimeException;
/**
 * Thrown when attempting to use a sort order that is not recognized.
 */
class InvalidSortOrderException extends RuntimeException
{
}
