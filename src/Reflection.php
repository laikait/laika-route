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

    /**
     * Container Resolver, When The Host Application Installed One
     * @var ?callable
     */
    protected $resolver;

    public function __construct($callable, array $params = [], ?callable $resolver = null)
    {
        $this->callable = $callable;
        $this->params = $params;
        $this->resolver = $resolver;
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
            $failure = null;

            // Route params win by name, ahead of anything the container holds
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

            // A class or interface type hint is a dependency: ask the container
            $type = $param->getType();
            $injectable = $this->resolver !== null
                && $type instanceof \ReflectionNamedType
                && !$type->isBuiltin();

            if ($injectable) {
                try {
                    $args[] = ($this->resolver)($type->getName());
                    continue;
                } catch (\Throwable $e) {
                    // Not resolvable. An optional parameter still has its default below.
                    $failure = $e;
                }
            }

            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
                continue;
            }

            // A dependency the container was asked for and could not build is a
            // wiring mistake, not an optional argument. Reporting it here beats a
            // null surfacing later inside the callee.
            if ($injectable) {
                throw new \RuntimeException(
                    "Cannot resolve \${$name} of type {$type->getName()} for {$this}."
                    . ' Bind it in a RelayProvider if it is an interface.',
                    0,
                    $failure
                );
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
