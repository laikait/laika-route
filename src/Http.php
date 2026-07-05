<?php

declare(strict_types=1);

namespace Laika\Route;

class Http
{
    protected array $lastRoute;

    public static function get(string $uri, $controller, array $middlewares = []): self
    {
        return static::make(Handler::get($uri, $controller, $middlewares));
    }

    public static function post(string $uri, $controller, array $middlewares = []): self
    {
        return static::make(Handler::post($uri, $controller, $middlewares));
    }

    public static function put(string $uri, $controller, array $middlewares = []): self
    {
        return static::make(Handler::put($uri, $controller, $middlewares));
    }

    public static function patch(string $uri, $controller, array $middlewares = []): self
    {
        return static::make(Handler::patch($uri, $controller, $middlewares));
    }

    public static function delete(string $uri, $controller, array $middlewares = []): self
    {
        return static::make(Handler::delete($uri, $controller, $middlewares));
    }

    public static function options(string $uri, $controller, array $middlewares = []): self
    {
        return static::make(Handler::options($uri, $controller, $middlewares));
    }

    protected static function make(array $route): self
    {
        $instance = new self();
        $instance->lastRoute = $route;
        return $instance;
    }

    protected array $pendingGroup = [];
    protected bool $groupExecuted = false;

    public static function group(string $prefix, callable $callback): self
    {
        $instance = new self();
        $instance->pendingGroup = ['prefix' => trim($prefix, '/')];
        Handler::registerGroup($prefix, $callback, [], []);
        $instance->groupExecuted = true;
        return $instance;
    }

    public function middleware(array $middlewares): self
    {
        if ($this->groupExecuted) {
            Handler::applyToPrefix($this->pendingGroup['prefix'], middlewares: $middlewares);
            return $this;
        }

        $method = $this->lastRoute['method'];
        $uri = $this->lastRoute['uri'];
        $routes = Handler::getRoutes();
        $routes[$method][$uri]['middlewares'] = array_merge(
            $routes[$method][$uri]['middlewares'],
            $middlewares
        );
        return $this;
    }

    public function afterware(array $afterwares): self
    {
        if ($this->groupExecuted) {
            Handler::applyToPrefix($this->pendingGroup['prefix'], afterwares: $afterwares);
            return $this;
        }

        $method = $this->lastRoute['method'];
        $uri = $this->lastRoute['uri'];
        $routes = Handler::getRoutes();
        $routes[$method][$uri]['afterwares'] = array_merge(
            $routes[$method][$uri]['afterwares'],
            $afterwares
        );
        return $this;
    }

    public static function globalMiddleware(array $middlewares): void
    {
        Handler::globalMiddleware($middlewares);
    }

    public static function globalAfterware(array $afterwares): void
    {
        Handler::globalAfterware($afterwares);
    }

    public static function fallback(?string $group, callable $callback, array $middlewares = []): void
    {
        Handler::registerFallback($group, $callback, $middlewares);
    }

    public static function dispatch(): void
    {
        Dispatcher::dispatch();
    }

    public function name(string $name): self
    {
        Handler::name($name, $this->lastRoute['method'], $this->lastRoute['uri']);
        return $this;
    }

    public static function url(string $name, array $params = []): string
    {
        return Handler::namedUrl($name, $params);
    }
}
