<?php

declare(strict_types=1);

namespace PureMapper\Hydration;

use PureMapper\Exception\HydrationException;
use PureMapper\Mapping\MetadataRegistryInterface;
use PureMapper\Type\TypeRegistry;
use ReflectionClass;

final class Hydrator
{
    /**
     * @var array<class-string, ReflectionClass<object>>
     */
    private array $reflectionCache = [];

    public function __construct(
        private readonly MetadataRegistryInterface $metadataRegistry,
        private readonly TypeRegistry $typeRegistry,
    ) {
    }

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
        $metadata = $this->metadataRegistry->get($class);
        $reflection = $this->getReflection($class);
        $entity = $reflection->newInstanceWithoutConstructor();

        foreach ($metadata->fields as $property => $field) {
            $column = $field->column;

            if (!array_key_exists($column, $row)) {
                continue;
            }

            $value = $row[$column];

            if ($value !== null && $this->typeRegistry->has($field->type)) {
                $converter = $this->typeRegistry->get($field->type);
                $value = $converter->toPHP($value);
            }

            $this->setPropertyValue($entity, $reflection, $property, $value);
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
        $metadata = $this->metadataRegistry->get($class);
        $reflection = $this->getReflection($class);
        $data = [];

        foreach ($metadata->fields as $property => $field) {
            $value = $this->getPropertyValue($entity, $reflection, $property);

            if ($value !== null && $this->typeRegistry->has($field->type)) {
                $converter = $this->typeRegistry->get($field->type);
                $value = $converter->toDatabase($value);
            }

            $data[$field->column] = $value;
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
        $metadata = $this->metadataRegistry->get($class);
        $reflection = $this->getReflection($class);

        $keys = is_array($metadata->primaryKey)
            ? $metadata->primaryKey
            : [$metadata->primaryKey];

        $values = [];
        foreach ($keys as $key) {
            $values[$key] = $this->getPropertyValue($entity, $reflection, $key);
        }

        return count($values) === 1 ? reset($values) : $values;
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
     * @param ReflectionClass<object> $reflection
     */
    private function setPropertyValue(
        object $entity,
        ReflectionClass $reflection,
        string $property,
        mixed $value,
    ): void {
        if (!$reflection->hasProperty($property)) {
            return;
        }

        $prop = $reflection->getProperty($property);
        $prop->setValue($entity, $value);
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private function getPropertyValue(
        object $entity,
        ReflectionClass $reflection,
        string $property,
    ): mixed {
        if (!$reflection->hasProperty($property)) {
            return null;
        }

        $prop = $reflection->getProperty($property);

        if (!$prop->isInitialized($entity)) {
            return null;
        }

        return $prop->getValue($entity);
    }
}
