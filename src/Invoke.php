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

use Laika\Core\Interfaces\PipelineInterface;
use Laika\Core\Interfaces\FilterInterface;

class Invoke
{
    /**
     * Invoke Pipeline
     * Run Before Response
     * @param string[] $pipelines
     * @param callable $core Call Next Pipeline
     * @param array $params
     * @return callable
     */
    public static function pipeline(array $pipelines, callable $core, array &$params = []): callable
    {
        $chain = $core;

        foreach (array_reverse($pipelines) as $pipeline) {
            $next = $chain;
            $chain = function (bool $continue = true) use ($pipeline, $next, &$params, $core) {
                if (!$continue) {
                    return $core();
                }

                [$class, $args] = static::parse($pipeline, 'App\\Pipeline\\');
                $params = array_merge($params, $args);

                if (!is_subclass_of($class, PipelineInterface::class) && !method_exists($class, 'handle')) {
                    throw new \RuntimeException("{$class} must implement PipelineInterface with handle().");
                }

                $instance = new $class();
                return $instance->handle($next, $params);
            };
        }

        return $chain;
    }

    /**
     * Invoke Filter
     * Run After Response
     * @param string[] $filters
     * @param ?string $response
     * @param array $params
     */
    public static function filter(array $filters, ?string $response, array &$params = [])
    {
        $core = fn($response) => $response;

        $chain = $core;

        foreach (array_reverse($filters) as $filter) {
            $next = $chain;
            $chain = function (?string $response, bool $continue = true) use ($filter, $next, &$params, $core) {
                if (!$continue) {
                    return $core($response);
                }

                [$class, $args] = static::parse($filter, 'App\\Filter\\');
                $params = array_merge($params, $args);

                if (!is_subclass_of($class, FilterInterface::class) && !method_exists($class, 'terminate')) {
                    throw new \RuntimeException("{$class} must implement FilterInterface with terminate().");
                }

                $instance = new $class();
                return $instance->terminate($next, $response, $params);
            };
        }

        return $chain($response);
    }

    /**
     * Invoke Response
     * @param mixed $response
     * @param array $params
     */
    public static function controller($controller, array $params = [])
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

    ###############################################################################
    /*============================ PARSE CLASS/STRING ============================*/
    ###############################################################################
    /**
     * Make Class & Params from String
     * @param string $entry Entry String
     * @param string $namespace Namespace
     * @return array
     */
    protected static function parse(string $entry, string $namespace): array
    {
        [$name, $argsStr] = array_pad(explode('|', $entry, 2), 2, null);

        if (str_starts_with($name, '\\') || class_exists($name)) {
            $class = ltrim($name, '\\');
        } else {
            $class = $namespace . $name;
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
