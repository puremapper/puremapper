<?php

declare(strict_types=1);

namespace PureMapper\Hydration;

use Closure;
use PureMapper\Mapping\EntityMetadata;
use PureMapper\Mapping\MetadataRegistryInterface;
use PureMapper\Type\TypeRegistry;
use ReflectionClass;

final class Hydrator
{
    /**
     * @var array<class-string, ReflectionClass<object>>
     */
    private array $reflectionCache = [];

    /**
     * Cached closure-based setters for direct property access.
     *
     * @var array<class-string, array<string, Closure>>
     */
    private array $setterCache = [];

    /**
     * Cached closure-based getters for direct property access.
     *
     * @var array<class-string, array<string, Closure>>
     */
    private array $getterCache = [];

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

            // Use closure-based setter for faster property access
            $setter = $this->getSetter($class, $property);
            $setter($entity, $value);
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
            // Use closure-based getter for faster property access
            $getter = $this->getGetter($class, $property);
            $value = $getter($entity);

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
            // Use closure-based getter for faster property access
            $getter = $this->getGetter($class, $key);
            $values[$key] = $getter($entity);
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
     * Get a cached closure-based setter for direct property access.
     * Closures bound to the class scope are faster than ReflectionProperty::setValue().
     *
     * @param class-string $class
     */
    private function getSetter(string $class, string $property): Closure
    {
        if (!isset($this->setterCache[$class][$property])) {
            /** @var Closure $setter */
            $setter = Closure::bind(
                static function (object $entity, mixed $value) use ($property): void {
                    $entity->{$property} = $value;
                },
                null,
                $class
            );

            $this->setterCache[$class][$property] = $setter;
        }

        return $this->setterCache[$class][$property];
    }

    /**
     * Get a cached closure-based getter for direct property access.
     * Closures bound to the class scope are faster than ReflectionProperty::getValue().
     *
     * @param class-string $class
     */
    private function getGetter(string $class, string $property): Closure
    {
        if (!isset($this->getterCache[$class][$property])) {
            /** @var Closure $getter */
            $getter = Closure::bind(
                static function (object $entity) use ($property): mixed {
                    return $entity->{$property} ?? null;
                },
                null,
                $class
            );

            $this->getterCache[$class][$property] = $getter;
        }

        return $this->getterCache[$class][$property];
    }
}
