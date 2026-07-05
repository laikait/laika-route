<?php

declare(strict_types=1);

namespace Laika\Route;

class Dispatcher
{
    protected static array $assetRoutes = [];
    protected static array $hookPaths = [];

    public static function preDispatcher(): void
    {
        date_default_timezone_set('UTC');
        static::registerInitiators();
    }

    public static function registerInitiators(): void
    {
        static::registerHeaders();
        static::loadHookFiles();
    }

    public static function registerHeaders(): void
    {
        if (!headers_sent()) {
            header('X-Powered-By: Laika');
        }
    }

    public static function registerAssetRoute(string $uri, string $filePath): void
    {
        static::$assetRoutes[Url::normalize($uri)] = $filePath;
    }

    public static function addHookPath(string $path): void
    {
        static::$hookPaths[] = $path;
    }

    public static function loadHookFiles(): void
    {
        foreach (static::$hookPaths as $path) {
            foreach (glob(rtrim($path, '/') . '/*hook.php') as $file) {
                require $file;
            }
        }
    }

    public static function dispatch(): void
    {
        static::preDispatcher();

        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
        $normalized = Url::normalize(Url::stripBasePath(parse_url($requestUri, PHP_URL_PATH) ?? '/'));

        if (isset(static::$assetRoutes[$normalized])) {
            static::serveAsset(static::$assetRoutes[$normalized]);
            return;
        }

        ['route' => $route, 'params' => $params] = Url::matchRequestRoute($requestUri);

        if ($route === null) {
            static::dispatchFallback($normalized);
            return;
        }

        $middlewares = array_merge(Handler::getGlobalMiddleware(), $route['middlewares']);
        $afterwares = array_merge(Handler::getGlobalAfterware(), $route['afterwares']);

        $core = function () use ($route, $params) {
            return Invoke::controller($route['controller'], $params);
        };

        $response = Invoke::middleware($middlewares, $core, $params)();

        Invoke::afterware($afterwares, $response, $params);
    }

    protected static function serveAsset(string $filePath): void
    {
        if (!is_file($filePath)) {
            http_response_code(404);
            echo _404::show();
            return;
        }

        $mime = mime_content_type($filePath) ?: 'application/octet-stream';
        header('Content-Type: ' . $mime);
        readfile($filePath);
    }

    protected static function dispatchFallback(string $uri): void
    {
        $fallbacks = Handler::getFallbacks();
        uksort($fallbacks, fn($a, $b) => strlen($b) - strlen($a));

        foreach ($fallbacks as $prefix => $fallback) {
            if (str_starts_with($uri . '/', $prefix)) {
                $response = Invoke::middleware(
                    $fallback['middlewares'],
                    fn() => ($fallback['callback'])()
                )();

                echo $response;
                return;
            }
        }

        http_response_code(404);
        echo _404::show();
    }
}
