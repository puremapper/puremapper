<?php

declare(strict_types=1);

namespace PureMapper\Query;

use BadMethodCallException;
use PureMapper\Hydration\Hydrator;
use PureMapper\Mapping\EntityMetadata;
use PureMapper\Mapping\MetadataRegistryInterface;
use PureMapper\Mapping\RelationType;
use PureMapper\Persistence\UnitOfWork;
use ReflectionProperty;

/**
 * @template T of object
 *
 * @method $this where(string $column, string $operator, mixed $value)
 * @method $this orWhere(string $column, string $operator, mixed $value)
 * @method $this whereIn(string $column, array $values)
 * @method $this whereNull(string $column)
 * @method $this whereNotNull(string $column)
 * @method $this orderBy(string $column, string $direction = 'ASC')
 * @method $this limit(int $limit)
 * @method $this offset(int $offset)
 */
final class EntityQuery
{
    /** @var string[] */
    private const COLUMN_MAPPING_METHODS = [
        'where',
        'orWhere',
        'whereIn',
        'whereNull',
        'whereNotNull',
        'orderBy',
    ];

    private SqlBuilder $builder;
    private EntityMetadata $metadata;

    /**
     * @var array<string>
     */
    private array $eagerLoad = [];

    /**
     * @param class-string<T> $entityClass
     */
    public function __construct(
        private readonly string $entityClass,
        private readonly Connection $connection,
        private readonly MetadataRegistryInterface $metadataRegistry,
        private readonly Hydrator $hydrator,
        private readonly UnitOfWork $unitOfWork,
    ) {
        $this->metadata = $this->metadataRegistry->get($this->entityClass);
        $this->builder = $this->connection->table($this->metadata->table);
    }

    /**
     * @param array<mixed> $args
     */
    public function __call(string $method, array $args): self
    {
        if (!method_exists($this->builder, $method)) {
            throw new BadMethodCallException(
                sprintf('Method %s::%s does not exist.', self::class, $method)
            );
        }

        // Column mapping for methods that need property → column conversion
        if (in_array($method, self::COLUMN_MAPPING_METHODS, true)) {
            $args[0] = $this->metadata->getColumnForProperty($args[0]) ?? $args[0];
        }

        $this->builder->$method(...$args);

        return $this;
    }

    /**
     * @param int|string|array<string, mixed> $id
     * @return T|null
     */
    public function find(int|string|array $id): ?object
    {
        // Check identity map first
        $existing = $this->unitOfWork->getIdentityMap()->get($this->entityClass, $id);
        if ($existing !== null) {
            /** @var T */
            return $existing;
        }

        // Create new builder for find (don't use $this->builder as it may have conditions)
        $builder = $this->connection->table($this->metadata->table);
        $this->applyPrimaryKeyCondition($builder, $id);

        $query = $builder->toSelect();
        $rows = $this->connection->select($query);

        if (empty($rows)) {
            return null;
        }

        return $this->hydrateAndRegister($rows[0]);
    }

    /**
     * @return T|null
     */
    public function first(): ?object
    {
        $this->builder->limit(1);
        $query = $this->builder->toSelect();
        $rows = $this->connection->select($query);

        if (empty($rows)) {
            return null;
        }

        $entity = $this->hydrateAndRegister($rows[0]);
        $this->loadRelations([$entity]);

        return $entity;
    }

    /**
     * @return array<T>
     */
    public function get(): array
    {
        $query = $this->builder->toSelect();
        $rows = $this->connection->select($query);
        $entities = [];

        foreach ($rows as $row) {
            $entities[] = $this->hydrateAndRegister($row);
        }

        $this->loadRelations($entities);

        return $entities;
    }

    /**
     * Eager load relations.
     *
     * @return $this
     */
    public function with(string ...$relations): self
    {
        $this->eagerLoad = array_merge($this->eagerLoad, $relations);

        return $this;
    }

    /**
     * @param int|string|array<string, mixed> $id
     */
    private function applyPrimaryKeyCondition(
        SqlBuilder $builder,
        int|string|array $id,
    ): void {
        $keys = \is_array($this->metadata->primaryKey)
            ? $this->metadata->primaryKey
            : [$this->metadata->primaryKey];

        $values = \is_array($id) ? $id : [$id];

        foreach ($keys as $i => $key) {
            $column = $this->metadata->fields[$key]->column ?? $key;
            $value = \is_array($id) ? ($id[$key] ?? $values[$i] ?? null) : $id;
            $builder->where($column, '=', $value);
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return T
     */
    private function hydrateAndRegister(array $row): object
    {
        // Check identity map first
        $id = $this->extractIdFromRow($row);
        $existing = $this->unitOfWork->getIdentityMap()->get($this->entityClass, $id);

        if ($existing !== null) {
            /** @var T */
            return $existing;
        }

        /** @var T $entity */
        $entity = $this->hydrator->hydrate($this->entityClass, $row);
        $this->unitOfWork->registerManaged($entity, $id);

        return $entity;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function extractIdFromRow(array $row): mixed
    {
        $keys = \is_array($this->metadata->primaryKey)
            ? $this->metadata->primaryKey
            : [$this->metadata->primaryKey];

        if (\count($keys) === 1) {
            $column = $this->metadata->fields[$keys[0]]->column ?? $keys[0];
            return $row[$column] ?? null;
        }

        $id = [];
        foreach ($keys as $key) {
            $column = $this->metadata->fields[$key]->column ?? $key;
            $id[$key] = $row[$column] ?? null;
        }

        return $id;
    }

    /**
     * @param array<T> $entities
     */
    private function loadRelations(array $entities): void
    {
        if (empty($entities) || empty($this->eagerLoad)) {
            return;
        }

        foreach ($this->eagerLoad as $relationName) {
            if (!isset($this->metadata->relations[$relationName])) {
                continue;
            }

            $relation = $this->metadata->relations[$relationName];

            match ($relation->type) {
                RelationType::HasOne => $this->loadHasOne($entities, $relationName),
                RelationType::HasMany => $this->loadHasMany($entities, $relationName),
                RelationType::BelongsTo => $this->loadBelongsTo($entities, $relationName),
                RelationType::ManyToMany => $this->loadManyToMany($entities, $relationName),
            };
        }
    }

    /**
     * @param array<object> $entities
     */
    private function loadHasOne(array $entities, string $relationName): void
    {
        $relation = $this->metadata->relations[$relationName];
        $targetMetadata = $this->metadataRegistry->get($relation->targetEntity);
        $foreignKey = $relation->foreignKey;

        // Collect parent IDs
        $parentIds = [];
        foreach ($entities as $entity) {
            $id = $this->hydrator->getIdentifier($entity);
            if ($id !== null) {
                $parentIds[] = $id;
            }
        }

        if (empty($parentIds)) {
            return;
        }

        // Load related entities
        $query = $this->connection->table($targetMetadata->table)
            ->whereIn($foreignKey, $parentIds)
            ->toSelect();
        $rows = $this->connection->select($query);

        // Index by foreign key
        $relatedByFk = [];
        foreach ($rows as $row) {
            $fkValue = $row[$foreignKey] ?? null;
            if ($fkValue !== null) {
                $relatedByFk[$fkValue] = $this->hydrator->hydrate($relation->targetEntity, $row);
            }
        }

        // Assign to entities
        $reflection = new ReflectionProperty($this->entityClass, $relationName);
        foreach ($entities as $entity) {
            $id = $this->hydrator->getIdentifier($entity);
            $related = $relatedByFk[$id] ?? null;
            $reflection->setValue($entity, $related);
        }
    }

    /**
     * @param array<object> $entities
     */
    private function loadHasMany(array $entities, string $relationName): void
    {
        $relation = $this->metadata->relations[$relationName];
        $targetMetadata = $this->metadataRegistry->get($relation->targetEntity);
        $foreignKey = $relation->foreignKey;

        // Collect parent IDs
        $parentIds = [];
        foreach ($entities as $entity) {
            $id = $this->hydrator->getIdentifier($entity);
            if ($id !== null) {
                $parentIds[] = $id;
            }
        }

        if (empty($parentIds)) {
            return;
        }

        // Load related entities
        $query = $this->connection->table($targetMetadata->table)
            ->whereIn($foreignKey, $parentIds)
            ->toSelect();
        $rows = $this->connection->select($query);

        // Group by foreign key
        $relatedByFk = [];
        foreach ($rows as $row) {
            $fkValue = $row[$foreignKey] ?? null;
            if ($fkValue !== null) {
                $relatedByFk[$fkValue][] = $this->hydrator->hydrate($relation->targetEntity, $row);
            }
        }

        // Assign to entities
        $reflection = new ReflectionProperty($this->entityClass, $relationName);
        foreach ($entities as $entity) {
            $id = $this->hydrator->getIdentifier($entity);
            $related = $relatedByFk[$id] ?? [];
            $reflection->setValue($entity, $related);
        }
    }

    /**
     * @param array<object> $entities
     */
    private function loadBelongsTo(array $entities, string $relationName): void
    {
        $relation = $this->metadata->relations[$relationName];
        $targetMetadata = $this->metadataRegistry->get($relation->targetEntity);
        $foreignKey = $relation->foreignKey;
        $targetPk = \is_array($targetMetadata->primaryKey)
            ? $targetMetadata->primaryKey[0]
            : $targetMetadata->primaryKey;
        $targetPkColumn = $targetMetadata->fields[$targetPk]->column ?? $targetPk;

        // Collect foreign key values
        $fkReflection = new ReflectionProperty($this->entityClass, $this->metadata->getPropertyForColumn($foreignKey) ?? $foreignKey);
        $fkValues = [];
        foreach ($entities as $entity) {
            if ($fkReflection->isInitialized($entity)) {
                $fkValue = $fkReflection->getValue($entity);
                if ($fkValue !== null) {
                    $fkValues[] = $fkValue;
                }
            }
        }

        if (empty($fkValues)) {
            return;
        }

        // Load related entities
        $query = $this->connection->table($targetMetadata->table)
            ->whereIn($targetPkColumn, array_unique($fkValues))
            ->toSelect();
        $rows = $this->connection->select($query);

        // Index by PK
        $relatedByPk = [];
        foreach ($rows as $row) {
            $pkValue = $row[$targetPkColumn] ?? null;
            if ($pkValue !== null) {
                $relatedByPk[$pkValue] = $this->hydrator->hydrate($relation->targetEntity, $row);
            }
        }

        // Assign to entities
        $reflection = new ReflectionProperty($this->entityClass, $relationName);
        foreach ($entities as $entity) {
            $fkValue = $fkReflection->isInitialized($entity) ? $fkReflection->getValue($entity) : null;
            $related = $fkValue !== null ? ($relatedByPk[$fkValue] ?? null) : null;
            $reflection->setValue($entity, $related);
        }
    }

    /**
     * @param array<object> $entities
     */
    private function loadManyToMany(array $entities, string $relationName): void
    {
        $relation = $this->metadata->relations[$relationName];
        $targetMetadata = $this->metadataRegistry->get($relation->targetEntity);
        $pivotTable = $relation->pivotTable;
        $foreignKey = $relation->foreignKey;
        $relatedKey = $relation->relatedKey;

        if ($pivotTable === null || $relatedKey === null) {
            return;
        }

        $targetPk = \is_array($targetMetadata->primaryKey)
            ? $targetMetadata->primaryKey[0]
            : $targetMetadata->primaryKey;
        $targetPkColumn = $targetMetadata->fields[$targetPk]->column ?? $targetPk;

        // Collect parent IDs
        $parentIds = [];
        foreach ($entities as $entity) {
            $id = $this->hydrator->getIdentifier($entity);
            if ($id !== null) {
                $parentIds[] = $id;
            }
        }

        if (empty($parentIds)) {
            return;
        }

        // Load pivot table entries
        $pivotQuery = $this->connection->table($pivotTable)
            ->whereIn($foreignKey, $parentIds)
            ->toSelect();
        $pivotRows = $this->connection->select($pivotQuery);

        // Collect related IDs
        $relatedIds = [];
        $pivotMap = [];
        foreach ($pivotRows as $row) {
            $parentId = $row[$foreignKey] ?? null;
            $relatedId = $row[$relatedKey] ?? null;
            if ($parentId !== null && $relatedId !== null) {
                $relatedIds[] = $relatedId;
                $pivotMap[$parentId][] = $relatedId;
            }
        }

        if (empty($relatedIds)) {
            return;
        }

        // Load related entities
        $query = $this->connection->table($targetMetadata->table)
            ->whereIn($targetPkColumn, array_unique($relatedIds))
            ->toSelect();
        $rows = $this->connection->select($query);

        // Index by PK
        $relatedByPk = [];
        foreach ($rows as $row) {
            $pkValue = $row[$targetPkColumn] ?? null;
            if ($pkValue !== null) {
                $relatedByPk[$pkValue] = $this->hydrator->hydrate($relation->targetEntity, $row);
            }
        }

        // Assign to entities
        $reflection = new ReflectionProperty($this->entityClass, $relationName);
        foreach ($entities as $entity) {
            $id = $this->hydrator->getIdentifier($entity);
            $relatedEntities = [];
            if ($id !== null && isset($pivotMap[$id])) {
                foreach ($pivotMap[$id] as $relatedId) {
                    if (isset($relatedByPk[$relatedId])) {
                        $relatedEntities[] = $relatedByPk[$relatedId];
                    }
                }
            }
            $reflection->setValue($entity, $relatedEntities);
        }
    }
}
