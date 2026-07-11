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

class Reflection
{
    protected \ReflectionFunctionAbstract $reflection;
    protected array $params;
    protected $callable;

    public function __construct($callable, array $params = [])
    {
        $this->callable = $callable;
        $this->params = $params;
        $this->reflection = $this->resolveReflection($callable);
    }

    protected function resolveReflection($callable): \ReflectionFunctionAbstract
    {
        if ($callable instanceof \Closure) {
            return new \ReflectionFunction($callable);
        }

        if (is_array($callable) && count($callable) === 2) {
            [$class, $method] = $callable;
            $class = is_object($class) ? get_class($class) : $class;
            return new \ReflectionMethod($class, $method);
        }

        if (is_string($callable) && str_contains($callable, '::')) {
            return new \ReflectionMethod($callable);
        }

        if (is_string($callable) && function_exists($callable)) {
            return new \ReflectionFunction($callable);
        }

        if (is_object($callable) && method_exists($callable, '__invoke')) {
            return new \ReflectionMethod($callable, '__invoke');
        }

        throw new \InvalidArgumentException('Unresolvable callable passed to Reflection.');
    }

    public function namedArgs(): array
    {
        $args = [];

        foreach ($this->reflection->getParameters() as $param) {
            $name = $param->getName();

            if (array_key_exists($name, $this->params)) {
                $args[] = $this->params[$name];
                continue;
            }

            if ($param->isVariadic()) {
                foreach ($this->params as $key => $value) {
                    if (!is_string($key)) {
                        $args[] = $value;
                    }
                }
                continue;
            }

            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
                continue;
            }

            if ($param->allowsNull()) {
                $args[] = null;
                continue;
            }

            throw new \RuntimeException("Missing required parameter: \${$name}");
        }

        return $args;
    }

    public function __toString(): string
    {
        $name = $this->reflection->getName();
        $class = $this->reflection instanceof \ReflectionMethod
            ? $this->reflection->getDeclaringClass()->getName() . '::'
            : '';

        return "Reflection({$class}{$name})";
    }
}
