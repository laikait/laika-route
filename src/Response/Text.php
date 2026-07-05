<?php
/**
 * Laika PHP MVC Framework
 * Author: Showket Ahmed
 * Email: riyadhtayf@gmail.com
 * License: MIT
 * This file is part of the Laika PHP MVC Framework.
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Laika\Route\Response;

use Laika\Service\Response;

final class Text
{
    /**
     * Render Html
     * @param string $str
     * @return void
     */
    public static function render(string $str): void
    {
        Response::text($str)->send();
    }
}