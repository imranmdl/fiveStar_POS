<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Small service container with constructor autowiring.
 *
 * Explicit factories win over autowiring, which keeps wiring of things like
 * the PDO connection or the SMS driver in one readable place
 * (bootstrap/container.php) while normal services need no registration.
 */
final class Container
{
    /** @var array<string, callable> */
    private array $factories = [];

    /** @var array<string, object> */
    private array $instances = [];

    /** @var array<string, bool> */
    private array $resolving = [];

    public function bind(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
    }

    public function instance(string $id, object $object): void
    {
        $this->instances[$id] = $object;
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->factories[$id]) || class_exists($id);
    }

    public function get(string $id): object
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if (isset($this->resolving[$id])) {
            throw new \RuntimeException("Circular dependency detected while resolving {$id}");
        }

        $this->resolving[$id] = true;

        try {
            $object = isset($this->factories[$id])
                ? ($this->factories[$id])($this)
                : $this->autowire($id);
        } finally {
            unset($this->resolving[$id]);
        }

        $this->instances[$id] = $object;

        return $object;
    }

    private function autowire(string $id): object
    {
        if (!class_exists($id)) {
            throw new \RuntimeException("Cannot resolve unknown service: {$id}");
        }

        $reflection = new \ReflectionClass($id);

        if (!$reflection->isInstantiable()) {
            throw new \RuntimeException("Service {$id} is not instantiable and has no factory registered");
        }

        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return new $id();
        }

        $arguments = [];

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $arguments[] = $this->get($type->getName());

                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();

                continue;
            }

            throw new \RuntimeException(
                "Cannot autowire parameter \${$parameter->getName()} of {$id}"
            );
        }

        return $reflection->newInstanceArgs($arguments);
    }
}
