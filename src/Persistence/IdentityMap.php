<?php

declare(strict_types=1);

namespace PureMapper\Persistence;

final class IdentityMap
{
    /**
     * @var array<class-string, array<string, object>>
     */
    private array $entities = [];

    /**
     * @param class-string $class
     */
    public function get(string $class, mixed $id): ?object
    {
        $key = $this->createKey($id);

        return $this->entities[$class][$key] ?? null;
    }

    /**
     * @param class-string $class
     */
    public function set(string $class, mixed $id, object $entity): void
    {
        $key = $this->createKey($id);
        $this->entities[$class][$key] = $entity;
    }

    /**
     * @param class-string $class
     */
    public function has(string $class, mixed $id): bool
    {
        $key = $this->createKey($id);

        return isset($this->entities[$class][$key]);
    }

    /**
     * @param class-string $class
     */
    public function remove(string $class, mixed $id): void
    {
        $key = $this->createKey($id);
        unset($this->entities[$class][$key]);
    }

    public function clear(): void
    {
        $this->entities = [];
    }

    /**
     * @param class-string $class
     * @return array<object>
     */
    public function getAll(string $class): array
    {
        return array_values($this->entities[$class] ?? []);
    }

    private function createKey(mixed $id): string
    {
        if (\is_array($id)) {
            return implode(':', array_map('strval', $id));
        }

        return (string) $id;
    }
}
