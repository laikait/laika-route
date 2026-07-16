<?php
/**
 * Laika Framework
 * Author: Showket Ahmed
 * Email: riyadhtayf@gmail.com
 * License: MIT
 * This file is part of the Laika PHP Framework.
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Laika\Route;

class Handler
{
    protected static array $routes = [];
    protected static array $namedRoutes = [];
    protected static array $fallbacks = [];
    protected static array $groupStack = [];

    protected static array $globalPipelines = [];
    protected static array $globalFilters = [];

    public static function register(string $method, string $uri, mixed $controller, string|array $pipelines = []): array
    {
        $method = strtoupper($method);
        $uri = static::applyGroupPrefix($uri);

        $groupPipelines = static::currentGroupPipelines();
        $groupFilters = static::currentGroupFilters();

        $route = [
            'method'        =>  $method,
            'uri'           =>  $uri,
            'controller'    =>  $controller,
            'pipelines'     =>  array_merge($groupPipelines, (array) $pipelines),
            'filters'       =>  $groupFilters,
            'name'          =>  null,
        ];

        static::$routes[$method][$uri] = $route;

        return $route;
    }

    public static function get(string $uri, mixed $controller, string|array $pipelines = []): array
    {
        return static::register('GET', $uri, $controller, $pipelines);
    }

    public static function post(string $uri, mixed $controller, string|array $pipelines = []): array
    {
        return static::register('POST', $uri, $controller, $pipelines);
    }

    public static function put(string $uri, mixed $controller, string|array $pipelines = []): array
    {
        return static::register('PUT', $uri, $controller, $pipelines);
    }

    public static function patch(string $uri, mixed $controller, string|array $pipelines = []): array
    {
        return static::register('PATCH', $uri, $controller, $pipelines);
    }

    public static function delete(string $uri, mixed $controller, string|array $pipelines = []): array
    {
        return static::register('DELETE', $uri, $controller, $pipelines);
    }

    public static function options(string $uri, mixed $controller, string|array $pipelines = []): array
    {
        return static::register('OPTIONS', $uri, $controller, $pipelines);
    }

    public static function registerGroup(string $prefix, callable $callback, string|array $pipelines = [], string|array $filters = []): void
    {
        static::$groupStack[] = [
            'prefix'        =>  trim($prefix, '/'),
            'pipelines'     =>  (array) $pipelines,
            'filters'       =>  (array) $filters,
        ];

        $callback();

        array_pop(static::$groupStack);
    }

    public static function registerFallback(?string $group, callable $callback, string|array $pipelines = []): void
    {
        $key = static::normalizeFallbackKey($group);
        static::$fallbacks[$key] = [
            'callback'      =>  $callback,
            'pipelines'     =>  (array) $pipelines,
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

    protected static function currentGroupPipelines(): array
    {
        $pipelines = [];
        foreach (static::$groupStack as $g) {
            $pipelines = array_merge($pipelines, $g['pipelines']);
        }
        return $pipelines;
    }

    protected static function currentGroupFilters(): array
    {
        $filters = [];
        foreach (static::$groupStack as $g) {
            $filters = array_merge($filters, $g['filters']);
        }
        return $filters;
    }

    public static function globalPipeline(string|array $pipelines): void
    {
        static::$globalPipelines = array_merge(static::$globalPipelines, (array) $pipelines);
    }

    public static function globalFilter(string|array $filters): void
    {
        static::$globalFilters = array_merge(static::$globalFilters, (array) $filters);
    }

    public static function getGlobalPipelines(): array
    {
        return static::$globalPipelines;
    }

    public static function getGlobalFilters(): array
    {
        return static::$globalFilters;
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

    public static function applyToPrefix(string $prefix, string|array $pipelines = [], string|array $filters = []): void
    {
        $prefix = '/' . trim($prefix, '/');
        $pipelines  = (array) $pipelines;
        $filters    = (array) $filters;

        foreach (static::$routes as $method => $routes) {
            foreach ($routes as $uri => $route) {
                if ($uri === $prefix || str_starts_with($uri, $prefix . '/')) {
                    if ($pipelines) {
                        static::$routes[$method][$uri]['pipelines'] = array_merge(
                            $route['pipelines'],
                            $pipelines
                        );
                    }
                    if ($filters) {
                        static::$routes[$method][$uri]['filters'] = array_merge(
                            $route['filters'],
                            $filters
                        );
                    }
                }
            }
        }
    }

    public static function appendPipeline(string $method, string $uri, string|array $pipelines): void
    {
        static::$routes[strtoupper($method)][$uri]['pipelines'] = array_merge(
            static::$routes[strtoupper($method)][$uri]['pipelines'],
            (array) $pipelines
        );
    }

    public static function appendFilter(string $method, string $uri, string|array $filters): void
    {
        static::$routes[strtoupper($method)][$uri]['filters'] = array_merge(
            static::$routes[strtoupper($method)][$uri]['filters'],
            (array) $filters
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
