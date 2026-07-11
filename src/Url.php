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

namespace Laika\Route;

class Url
{
    public static function normalize(string $uri): string
    {
        $uri = '/' . trim($uri, '/');
        return $uri === '//' ? '/' : $uri;
    }

    public static function normalizeFallbackKey(?string $group): string
    {
        if ($group === null || $group === '') {
            return '/';
        }
        return '/' . trim($group, '/') . '/';
    }

    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public static function matchRequestRoute(string $requestUri): array
    {
        $method = static::method();
        $routes = Handler::getOnlyRoutes($method);
        $requestUri = static::normalize(static::stripBasePath(parse_url($requestUri, PHP_URL_PATH) ?? '/'));

        foreach ($routes as $uri => $route) {
            $pattern = static::compilePattern($uri);

            if (preg_match($pattern, $requestUri, $matches)) {
                $params = array_filter(
                    $matches,
                    fn($key) => !is_int($key),
                    ARRAY_FILTER_USE_KEY
                );

                return ['route' => $route, 'params' => $params];
            }
        }

        return ['route' => null, 'params' => []];
    }

    public static function stripBasePath(string $path): string
    {
        $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');

        if ($basePath !== '' && $basePath !== '/' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath));
        }

        return $path === '' ? '/' : $path;
    }

    protected static function compilePattern(string $uri): string
    {
        $pattern = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)(:([^}]+))?\}#',
            function ($m) {
                $name = $m[1];
                $regex = $m[3] ?? '[^/]+';
                return "(?P<{$name}>{$regex})";
            },
            $uri
        );

        return '#^' . $pattern . '$#';
    }

    public static function loadRoutes(?string $path = null): void
    {
        $path = $path ?? APP_PATH . '/lf-routes';
        foreach (glob(rtrim($path, '/') . '/*.php') as $file) {
            require_once $file;
        }
    }
}
