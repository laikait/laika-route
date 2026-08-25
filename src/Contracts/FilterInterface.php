<?php

declare(strict_types=1);

namespace Laika\Route\Contracts;

interface FilterInterface
{
    public function terminate(callable $next, ?string $response, array &$params): ?string;
}
