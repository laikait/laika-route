<?php

declare(strict_types=1);

namespace Laika\Route\Interface;

interface AfterwareInterface
{
    public function terminate(array &$params, $response): void;
}
