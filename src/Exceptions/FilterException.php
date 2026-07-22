<?php
/**
 * Laika Framework
 * Author: Showket Ahmed
 * Email: riyadhtayf@gmail.com
 * License: MIT
 * This file is part of the Laika PHP MVC Framework.
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Laika\Route\Exceptions;

use RuntimeException;

class FilterException extends RuntimeException
{
    protected int $statusCode;

    public function __construct(string $message, $code = 0, ?\Throwable $previous = null)
    {
        $this->statusCode = $code;
        parent::__construct($message, $code, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
