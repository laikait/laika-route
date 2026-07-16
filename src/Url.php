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

class Url
{
    protected array $lastRoute;

    public static function get(string $uri, mixed $controller, string|array $pipelines = []): self
    {
        return static::make(Handler::get($uri, $controller, $pipelines));
    }

    public static function post(string $uri, mixed $controller, string|array $pipelines = []): self
    {
        return static::make(Handler::post($uri, $controller, $pipelines));
    }

    public static function put(string $uri, mixed $controller, string|array $pipelines = []): self
    {
        return static::make(Handler::put($uri, $controller, $pipelines));
    }

    public static function patch(string $uri, mixed $controller, string|array $pipelines = []): self
    {
        return static::make(Handler::patch($uri, $controller, $pipelines));
    }

    public static function delete(string $uri, mixed $controller, string|array $pipelines = []): self
    {
        return static::make(Handler::delete($uri, $controller, $pipelines));
    }

    public static function options(string $uri, mixed $controller, string|array $pipelines = []): self
    {
        return static::make(Handler::options($uri, $controller, $pipelines));
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

    public function pipeline(string|array $pipelines): self
    {
        $this->groupExecuted ?
            Handler::applyToPrefix($this->pendingGroup['prefix'], pipelines: $pipelines) :
            Handler::appendPipeline($this->lastRoute['method'], $this->lastRoute['uri'], $pipelines);
        return $this;
    }

    public function filter(string|array $filters): self
    {
        $this->groupExecuted ?
            Handler::applyToPrefix($this->pendingGroup['prefix'], filters: $filters) :
            Handler::appendFilter($this->lastRoute['method'], $this->lastRoute['uri'], (array) $filters);
        return $this;   
    }

    public static function globalPipeline(string|array $pipelines): void
    {
        Handler::globalPipeline($pipelines);
    }

    public static function globalFilter(string|array $filters): void
    {
        Handler::globalFilter($filters);
    }

    public static function fallback(?string $group, callable $callback, string|array $pipelines = []): void
    {
        Handler::registerFallback($group, $callback, $pipelines);
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
