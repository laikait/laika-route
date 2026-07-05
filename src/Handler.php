<?php

declare(strict_types=1);

namespace Laika\Route;

class Handler
{
    protected static array $routes = [];
    protected static array $namedRoutes = [];
    protected static array $fallbacks = [];
    protected static array $groupStack = [];

    protected static array $globalMiddleware = [];
    protected static array $globalAfterware = [];

    public static function register(string $method, string $uri, mixed $controller, array $middlewares = []): array
    {
        $method = strtoupper($method);
        $uri = static::applyGroupPrefix($uri);

        $groupMiddlewares = static::currentGroupMiddlewares();
        $groupAfterwares = static::currentGroupAfterwares();

        $route = [
            'method' => $method,
            'uri' => $uri,
            'controller' => $controller,
            'middlewares' => array_merge($groupMiddlewares, $middlewares),
            'afterwares' => $groupAfterwares,
            'name' => null,
        ];

        static::$routes[$method][$uri] = $route;

        return $route;
    }

    public static function get(string $uri, mixed $controller, array $middlewares = []): array
    {
        return static::register('GET', $uri, $controller, $middlewares);
    }

    public static function post(string $uri, mixed $controller, array $middlewares = []): array
    {
        return static::register('POST', $uri, $controller, $middlewares);
    }

    public static function put(string $uri, mixed $controller, array $middlewares = []): array
    {
        return static::register('PUT', $uri, $controller, $middlewares);
    }

    public static function patch(string $uri, mixed $controller, array $middlewares = []): array
    {
        return static::register('PATCH', $uri, $controller, $middlewares);
    }

    public static function delete(string $uri, mixed $controller, array $middlewares = []): array
    {
        return static::register('DELETE', $uri, $controller, $middlewares);
    }

    public static function options(string $uri, mixed $controller, array $middlewares = []): array
    {
        return static::register('OPTIONS', $uri, $controller, $middlewares);
    }

    public static function registerGroup(string $prefix, callable $callback, array $middlewares = [], array $afterwares = []): void
    {
        static::$groupStack[] = [
            'prefix' => trim($prefix, '/'),
            'middlewares' => $middlewares,
            'afterwares' => $afterwares,
        ];

        $callback();

        array_pop(static::$groupStack);
    }

    public static function registerFallback(?string $group, callable $callback, array $middlewares = []): void
    {
        $key = static::normalizeFallbackKey($group);
        static::$fallbacks[$key] = [
            'callback' => $callback,
            'middlewares' => $middlewares,
        ];
    }

    protected static function normalizeFallbackKey(?string $group): string
    {
        if ($group === null || $group === '') {
            return '/';
        }
        return '/' . trim($group, '/') . '/';
    }

    protected static function applyGroupPrefix(string $uri): string
    {
        $prefixes = array_map(fn($g) => $g['prefix'], static::$groupStack);
        $prefix = implode('/', array_filter($prefixes));
        $uri = trim($uri, '/');
        $full = trim($prefix . '/' . $uri, '/');
        return '/' . $full;
    }

    protected static function currentGroupMiddlewares(): array
    {
        $middlewares = [];
        foreach (static::$groupStack as $g) {
            $middlewares = array_merge($middlewares, $g['middlewares']);
        }
        return $middlewares;
    }

    protected static function currentGroupAfterwares(): array
    {
        $afterwares = [];
        foreach (static::$groupStack as $g) {
            $afterwares = array_merge($afterwares, $g['afterwares']);
        }
        return $afterwares;
    }

    public static function globalMiddleware(array $middlewares): void
    {
        static::$globalMiddleware = array_merge(static::$globalMiddleware, $middlewares);
    }

    public static function globalAfterware(array $afterwares): void
    {
        static::$globalAfterware = array_merge(static::$globalAfterware, $afterwares);
    }

    public static function getGlobalMiddleware(): array
    {
        return static::$globalMiddleware;
    }

    public static function getGlobalAfterware(): array
    {
        return static::$globalAfterware;
    }

    public static function name(string $name, string $method, string $uri): void
    {
        $method = strtoupper($method);
        if (isset(static::$namedRoutes[$name])) {
            throw new \RuntimeException("Route name '{$name}' is already registered.");
        }

        if (!isset(static::$routes[$method][$uri])) {
            throw new \RuntimeException("Cannot name unregistered route: {$method} {$uri}");
        }

        static::$routes[$method][$uri]['name'] = $name;
        static::$namedRoutes[$name] = ['method' => $method, 'uri' => $uri];
    }

    public static function namedUrl(string $name, array $params = []): string
    {
        if (!isset(static::$namedRoutes[$name])) {
            throw new \RuntimeException("Named route '{$name}' not found.");
        }

        $uri = static::$namedRoutes[$name]['uri'];

        foreach ($params as $key => $value) {
            $uri = str_replace('{' . $key . '}', (string) $value, $uri);
        }

        return $uri;
    }

    public static function applyToPrefix(string $prefix, array $middlewares = [], array $afterwares = []): void
    {
        $prefix = '/' . trim($prefix, '/');

        foreach (static::$routes as $method => $routes) {
            foreach ($routes as $uri => $route) {
                if ($uri === $prefix || str_starts_with($uri, $prefix . '/')) {
                    if ($middlewares) {
                        static::$routes[$method][$uri]['middlewares'] = array_merge(
                            $route['middlewares'],
                            $middlewares
                        );
                    }
                    if ($afterwares) {
                        static::$routes[$method][$uri]['afterwares'] = array_merge(
                            $route['afterwares'],
                            $afterwares
                        );
                    }
                }
            }
        }
    }

    public static function appendMiddleware(string $method, string $uri, array $middlewares): void
    {
        static::$routes[strtoupper($method)][$uri]['middlewares'] = array_merge(
            static::$routes[strtoupper($method)][$uri]['middlewares'],
            $middlewares
        );
    }

    public static function appendAfterware(string $method, string $uri, array $afterwares): void
    {
        static::$routes[strtoupper($method)][$uri]['afterwares'] = array_merge(
            static::$routes[strtoupper($method)][$uri]['afterwares'],
            $afterwares
        );
    }

    public static function getRoutes(): array
    {
        return static::$routes;
    }

    public static function getOnlyRoutes(string $method): array
    {
        return static::$routes[strtoupper($method)] ?? [];
    }

    public static function getNamedRoutes(): array
    {
        return static::$namedRoutes;
    }

    public static function getGroups(): array
    {
        return static::$groupStack;
    }

    public static function getFallbacks(): array
    {
        return static::$fallbacks;
    }
}
