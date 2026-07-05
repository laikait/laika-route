<?php

declare(strict_types=1);

namespace Laika\Route;

interface MiddlewareInterface
{
    public function handle(array $params, callable $next);
}
