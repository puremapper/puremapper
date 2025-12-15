<?php

declare(strict_types=1);

namespace PureMapper\Hydration;

use PureMapper\Exception\HydrationException;
use PureMapper\Mapping\EntityMetadata;
use PureMapper\Mapping\MetadataRegistryInterface;
use PureMapper\Type\TypeRegistry;
use ReflectionClass;
use ReflectionProperty;

final class Hydrator
{
    /**
     * @var array<class-string, ReflectionClass<object>>
     */
    private array $reflectionCache = [];

    /**
     * @var array<class-string, array<string, ReflectionProperty>>
     */
    private array $propertyCache = [];

    /**
     * Local metadata cache to avoid repeated registry lookups.
     *
     * @var array<class-string, EntityMetadata>
     */
    private array $metadataCache = [];

    public function __construct(
        private readonly MetadataRegistryInterface $metadataRegistry,
        private readonly TypeRegistry $typeRegistry,
    ) {}

    /**
     * Create an entity instance from a database row.
     *
     * @template T of object
     * @param class-string<T> $class
     * @param array<string, mixed> $row
     * @return T
     * @throws HydrationException
     */
    public function hydrate(string $class, array $row): object
    {
        // Cache metadata locally to avoid repeated registry lookups
        $metadata = $this->metadataCache[$class] ??= $this->metadataRegistry->get($class);

        $reflection = $this->getReflection($class);
        $entity = $reflection->newInstanceWithoutConstructor();

        // Use precomputed maps for O(1) lookups
        foreach ($row as $column => $value) {
            // O(1) column → property lookup
            $property = $metadata->columnToProperty[$column] ?? null;

            if ($property === null) {
                continue;
            }

            if ($value !== null) {
                // O(1) property → type lookup
                $type = $metadata->propertyToType[$property] ?? null;

                if ($type !== null && $this->typeRegistry->has($type)) {
                    $value = $this->typeRegistry->get($type)->toPHP($value);
                }
            }

            $this->setPropertyValue($entity, $class, $property, $value);
        }

        return $entity;
    }

    /**
     * Extract data from an entity for database persistence.
     *
     * @param object $entity
     * @return array<string, mixed>
     */
    public function extract(object $entity): array
    {
        $class = $entity::class;
        // Cache metadata locally to avoid repeated registry lookups
        $metadata = $this->metadataCache[$class] ??= $this->metadataRegistry->get($class);
        $data = [];

        // Use precomputed maps for O(1) lookups
        foreach ($metadata->propertyToColumn as $property => $column) {
            $value = $this->getPropertyValue($entity, $class, $property);

            if ($value !== null) {
                // O(1) property → type lookup
                $type = $metadata->propertyToType[$property] ?? null;

                if ($type !== null && $this->typeRegistry->has($type)) {
                    $value = $this->typeRegistry->get($type)->toDatabase($value);
                }
            }

            $data[$column] = $value;
        }

        return $data;
    }

    /**
     * Get the primary key value(s) from an entity.
     *
     * @return mixed Single value or array for composite keys
     */
    public function getIdentifier(object $entity): mixed
    {
        $class = $entity::class;
        // Cache metadata locally to avoid repeated registry lookups
        $metadata = $this->metadataCache[$class] ??= $this->metadataRegistry->get($class);

        $keys = \is_array($metadata->primaryKey)
            ? $metadata->primaryKey
            : [$metadata->primaryKey];

        $values = [];
        foreach ($keys as $key) {
            $values[$key] = $this->getPropertyValue($entity, $class, $key);
        }

        return \count($values) === 1 ? reset($values) : $values;
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return ReflectionClass<T>
     */
    private function getReflection(string $class): ReflectionClass
    {
        if (!isset($this->reflectionCache[$class])) {
            $this->reflectionCache[$class] = new ReflectionClass($class);
        }

        /** @var ReflectionClass<T> */
        return $this->reflectionCache[$class];
    }

    /**
     * Get a cached ReflectionProperty instance.
     *
     * @param class-string $class
     */
    private function getProperty(string $class, string $property): ?ReflectionProperty
    {
        if (!isset($this->propertyCache[$class][$property])) {
            $reflection = $this->getReflection($class);

            if (!$reflection->hasProperty($property)) {
                return null;
            }

            $this->propertyCache[$class][$property] = $reflection->getProperty($property);
        }

        return $this->propertyCache[$class][$property];
    }

    /**
     * @param class-string $class
     */
    private function setPropertyValue(
        object $entity,
        string $class,
        string $property,
        mixed $value,
    ): void {
        $prop = $this->getProperty($class, $property);

        if ($prop === null) {
            return;
        }

        $prop->setValue($entity, $value);
    }

    /**
     * @param class-string $class
     */
    private function getPropertyValue(
        object $entity,
        string $class,
        string $property,
    ): mixed {
        $prop = $this->getProperty($class, $property);

        if ($prop === null) {
            return null;
        }

        if (!$prop->isInitialized($entity)) {
            return null;
        }

        return $prop->getValue($entity);
    }
}
