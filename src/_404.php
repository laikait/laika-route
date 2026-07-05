<?php

declare(strict_types=1);

namespace Laika\Route;

class _404
{
    public static function show(): string
    {
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>404 Not Found</title>
    <style>
        body { background:#000; color:#501f1f; display:flex; align-items:center; justify-content:center; height:100vh; margin:0; font-family:monospace; }
        h1 { font-size:8vw; letter-spacing:4px; }
    </style>
</head>
<body>
    <h1>!404</h1>
</body>
</html>
HTML;
    }
}
