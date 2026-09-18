<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/Stub/Service.php';

// Asset::serve() resolves every path against APP_PATH, so the fixture tree is
// the application root for the whole suite.
define('APP_PATH', __DIR__ . '/fixtures/app');
