<?php

declare(strict_types=1);

namespace Laika\Route\Interface;

interface MiddlewareInterface
{
    public function handle(callable $next, array &$params);
}
