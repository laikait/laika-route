<?php

declare(strict_types=1);

namespace Laika\Route;

class Invoke
{
    public static function middleware(array $middlewares, callable $core, array &$params = []): callable
    {
        $chain = array_reduce(
            array_reverse($middlewares),
            function ($next, $middleware) use (&$params) {
                return function () use ($middleware, $next, &$params) {
                    [$class, $args] = static::parse($middleware, 'App\\Middleware\\');
                    $params = array_merge($params, $args);

                    if (!is_subclass_of($class, MiddlewareInterface::class) && !method_exists($class, 'handle')) {
                        throw new \RuntimeException("{$class} must implement MiddlewareInterface with handle().");
                    }

                    $instance = new $class();
                    return $instance->handle($next, $params);
                };
            },
            $core
        );

        return $chain;
    }

    public static function afterware(array $afterwares, $response, array &$params = []): void
    {
        foreach ($afterwares as $afterware) {
            [$class, $args] = static::parse($afterware, 'App\\Afterware\\');
            $params = array_merge($params, $args);

            if (!is_subclass_of($class, AfterwareInterface::class) && !method_exists($class, 'terminate')) {
                throw new \RuntimeException("{$class} must implement AfterwareInterface with terminate().");
            }

            (new $class())->terminate($params, $response);
        }
    }

    public static function controller(mixed $controller, array $params = [])
    {
        if ($controller === null) {
            return null;
        }

        if ($controller instanceof \Closure) {
            $reflection = new Reflection($controller, $params);
            return $controller(...$reflection->namedArgs());
        }

        if (is_array($controller) && count($controller) === 2) {
            [$class, $method] = $controller;
            $instance = is_object($class) ? $class : new $class();
            $reflection = new Reflection([$instance, $method], $params);
            return $instance->{$method}(...$reflection->namedArgs());
        }

        if (is_string($controller) && str_contains($controller, '@')) {
            [$class, $method] = explode('@', $controller, 2);
            if (!str_starts_with($class, '\\')) {
                $class = 'App\\Controller\\' . $class;
            }
            $instance = new $class();
            $reflection = new Reflection([$instance, $method], $params);
            return $instance->{$method}(...$reflection->namedArgs());
        }

        if (is_string($controller)) {
            if (!str_starts_with($controller, '\\')) {
                $controller = 'App\\Controller\\' . $controller;
            }
            $instance = new $controller();
            $reflection = new Reflection([$instance, '__invoke'], $params);
            return $instance(...$reflection->namedArgs());
        }

        throw new \InvalidArgumentException('Unresolvable controller.');
    }

    protected static function parse(string $entry, string $prefix): array
    {
        [$name, $argsStr] = array_pad(explode('|', $entry, 2), 2, null);

        if (str_starts_with($name, '\\') || class_exists($name)) {
            $class = ltrim($name, '\\');
        } else {
            $class = $prefix . $name;
        }

        $params = [];
        if ($argsStr) {
            foreach (explode(',', $argsStr) as $pair) {
                [$k, $v] = array_pad(explode('=', $pair, 2), 2, true);
                $params[$k] = $v;
            }
        }

        return [$class, $params];
    }
}
