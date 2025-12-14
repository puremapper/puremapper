<?php

declare(strict_types=1);

namespace PureMapper\Query;

use Illuminate\Database\ConnectionInterface;
use PureMapper\Hydration\Hydrator;
use PureMapper\Mapping\EntityMetadata;
use PureMapper\Mapping\MetadataRegistryInterface;
use PureMapper\Mapping\RelationType;
use PureMapper\Persistence\UnitOfWork;

/**
 * @template T of object
 */
final class EntityQuery
{
    /**
     * @var array<string, mixed>
     */
    private array $wheres = [];

    /**
     * @var array<array{column: string, direction: string}>
     */
    private array $orderBys = [];

    private ?int $limitValue = null;
    private ?int $offsetValue = null;

    /**
     * @var array<string>
     */
    private array $eagerLoad = [];

    /**
     * @param class-string<T> $entityClass
     */
    public function __construct(
        private readonly string $entityClass,
        private readonly ConnectionInterface $connection,
        private readonly MetadataRegistryInterface $metadataRegistry,
        private readonly Hydrator $hydrator,
        private readonly UnitOfWork $unitOfWork,
    ) {
    }

    /**
     * @return T|null
     */
    public function find(int|string|array $id): ?object
    {
        $metadata = $this->metadataRegistry->get($this->entityClass);

        // Check identity map first
        $existing = $this->unitOfWork->getIdentityMap()->get($this->entityClass, $id);
        if ($existing !== null) {
            return $existing;
        }

        $query = $this->connection->table($metadata->table);

        $this->applyPrimaryKeyCondition($query, $metadata, $id);

        $row = $query->first();

        if ($row === null) {
            return null;
        }

        return $this->hydrateAndRegister((array) $row, $metadata);
    }

    /**
     * @return T|null
     */
    public function first(): ?object
    {
        $metadata = $this->metadataRegistry->get($this->entityClass);
        $query = $this->buildQuery($metadata);

        $row = $query->first();

        if ($row === null) {
            return null;
        }

        $entity = $this->hydrateAndRegister((array) $row, $metadata);
        $this->loadRelations([$entity], $metadata);

        return $entity;
    }

    /**
     * @return array<T>
     */
    public function get(): array
    {
        $metadata = $this->metadataRegistry->get($this->entityClass);
        $query = $this->buildQuery($metadata);

        $rows = $query->get();
        $entities = [];

        foreach ($rows as $row) {
            $entities[] = $this->hydrateAndRegister((array) $row, $metadata);
        }

        $this->loadRelations($entities, $metadata);

        return $entities;
    }

    /**
     * @return $this
     */
    public function where(string $column, string $operator, mixed $value): self
    {
        $this->wheres[] = [
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
        ];

        return $this;
    }

    /**
     * @return $this
     */
    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $this->orderBys[] = [
            'column' => $column,
            'direction' => $direction,
        ];

        return $this;
    }

    /**
     * @return $this
     */
    public function limit(int $limit): self
    {
        $this->limitValue = $limit;

        return $this;
    }

    /**
     * @return $this
     */
    public function offset(int $offset): self
    {
        $this->offsetValue = $offset;

        return $this;
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
     * @return \Illuminate\Database\Query\Builder
     */
    private function buildQuery(EntityMetadata $metadata): \Illuminate\Database\Query\Builder
    {
        $query = $this->connection->table($metadata->table);

        foreach ($this->wheres as $where) {
            $column = $metadata->getColumnForProperty($where['column']) ?? $where['column'];
            $query->where($column, $where['operator'], $where['value']);
        }

        foreach ($this->orderBys as $orderBy) {
            $column = $metadata->getColumnForProperty($orderBy['column']) ?? $orderBy['column'];
            $query->orderBy($column, $orderBy['direction']);
        }

        if ($this->limitValue !== null) {
            $query->limit($this->limitValue);
        }

        if ($this->offsetValue !== null) {
            $query->offset($this->offsetValue);
        }

        return $query;
    }

    /**
     * @param \Illuminate\Database\Query\Builder $query
     */
    private function applyPrimaryKeyCondition(
        \Illuminate\Database\Query\Builder $query,
        EntityMetadata $metadata,
        int|string|array $id,
    ): void {
        $keys = is_array($metadata->primaryKey)
            ? $metadata->primaryKey
            : [$metadata->primaryKey];

        $values = is_array($id) ? $id : [$id];

        foreach ($keys as $i => $key) {
            $column = $metadata->fields[$key]->column ?? $key;
            $value = is_array($id) ? ($id[$key] ?? $values[$i] ?? null) : $id;
            $query->where($column, '=', $value);
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return T
     */
    private function hydrateAndRegister(array $row, EntityMetadata $metadata): object
    {
        // Check identity map first
        $id = $this->extractIdFromRow($row, $metadata);
        $existing = $this->unitOfWork->getIdentityMap()->get($this->entityClass, $id);

        if ($existing !== null) {
            return $existing;
        }

        $entity = $this->hydrator->hydrate($this->entityClass, $row);
        $this->unitOfWork->registerManaged($entity, $id);

        return $entity;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function extractIdFromRow(array $row, EntityMetadata $metadata): mixed
    {
        $keys = is_array($metadata->primaryKey)
            ? $metadata->primaryKey
            : [$metadata->primaryKey];

        if (count($keys) === 1) {
            $column = $metadata->fields[$keys[0]]->column ?? $keys[0];
            return $row[$column] ?? null;
        }

        $id = [];
        foreach ($keys as $key) {
            $column = $metadata->fields[$key]->column ?? $key;
            $id[$key] = $row[$column] ?? null;
        }

        return $id;
    }

    /**
     * @param array<T> $entities
     */
    private function loadRelations(array $entities, EntityMetadata $metadata): void
    {
        if (empty($entities) || empty($this->eagerLoad)) {
            return;
        }

        foreach ($this->eagerLoad as $relationName) {
            if (!isset($metadata->relations[$relationName])) {
                continue;
            }

            $relation = $metadata->relations[$relationName];

            match ($relation->type) {
                RelationType::HasOne => $this->loadHasOne($entities, $metadata, $relationName),
                RelationType::HasMany => $this->loadHasMany($entities, $metadata, $relationName),
                RelationType::BelongsTo => $this->loadBelongsTo($entities, $metadata, $relationName),
                RelationType::ManyToMany => $this->loadManyToMany($entities, $metadata, $relationName),
            };
        }
    }

    /**
     * @param array<object> $entities
     */
    private function loadHasOne(array $entities, EntityMetadata $metadata, string $relationName): void
    {
        $relation = $metadata->relations[$relationName];
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
        $rows = $this->connection->table($targetMetadata->table)
            ->whereIn($foreignKey, $parentIds)
            ->get();

        // Index by foreign key
        $relatedByFk = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $fkValue = $row[$foreignKey] ?? null;
            if ($fkValue !== null) {
                $relatedByFk[$fkValue] = $this->hydrator->hydrate($relation->targetEntity, $row);
            }
        }

        // Assign to entities
        $reflection = new \ReflectionProperty($this->entityClass, $relationName);
        foreach ($entities as $entity) {
            $id = $this->hydrator->getIdentifier($entity);
            $related = $relatedByFk[$id] ?? null;
            $reflection->setValue($entity, $related);
        }
    }

    /**
     * @param array<object> $entities
     */
    private function loadHasMany(array $entities, EntityMetadata $metadata, string $relationName): void
    {
        $relation = $metadata->relations[$relationName];
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
        $rows = $this->connection->table($targetMetadata->table)
            ->whereIn($foreignKey, $parentIds)
            ->get();

        // Group by foreign key
        $relatedByFk = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $fkValue = $row[$foreignKey] ?? null;
            if ($fkValue !== null) {
                $relatedByFk[$fkValue][] = $this->hydrator->hydrate($relation->targetEntity, $row);
            }
        }

        // Assign to entities
        $reflection = new \ReflectionProperty($this->entityClass, $relationName);
        foreach ($entities as $entity) {
            $id = $this->hydrator->getIdentifier($entity);
            $related = $relatedByFk[$id] ?? [];
            $reflection->setValue($entity, $related);
        }
    }

    /**
     * @param array<object> $entities
     */
    private function loadBelongsTo(array $entities, EntityMetadata $metadata, string $relationName): void
    {
        $relation = $metadata->relations[$relationName];
        $targetMetadata = $this->metadataRegistry->get($relation->targetEntity);
        $foreignKey = $relation->foreignKey;
        $targetPk = is_array($targetMetadata->primaryKey)
            ? $targetMetadata->primaryKey[0]
            : $targetMetadata->primaryKey;
        $targetPkColumn = $targetMetadata->fields[$targetPk]->column ?? $targetPk;

        // Collect foreign key values
        $fkReflection = new \ReflectionProperty($this->entityClass, $this->getPropertyForColumn($metadata, $foreignKey) ?? $foreignKey);
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
        $rows = $this->connection->table($targetMetadata->table)
            ->whereIn($targetPkColumn, array_unique($fkValues))
            ->get();

        // Index by PK
        $relatedByPk = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $pkValue = $row[$targetPkColumn] ?? null;
            if ($pkValue !== null) {
                $relatedByPk[$pkValue] = $this->hydrator->hydrate($relation->targetEntity, $row);
            }
        }

        // Assign to entities
        $reflection = new \ReflectionProperty($this->entityClass, $relationName);
        foreach ($entities as $entity) {
            $fkValue = $fkReflection->isInitialized($entity) ? $fkReflection->getValue($entity) : null;
            $related = $fkValue !== null ? ($relatedByPk[$fkValue] ?? null) : null;
            $reflection->setValue($entity, $related);
        }
    }

    /**
     * @param array<object> $entities
     */
    private function loadManyToMany(array $entities, EntityMetadata $metadata, string $relationName): void
    {
        $relation = $metadata->relations[$relationName];
        $targetMetadata = $this->metadataRegistry->get($relation->targetEntity);
        $pivotTable = $relation->pivotTable;
        $foreignKey = $relation->foreignKey;
        $relatedKey = $relation->relatedKey;

        if ($pivotTable === null || $relatedKey === null) {
            return;
        }

        $targetPk = is_array($targetMetadata->primaryKey)
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
        $pivotRows = $this->connection->table($pivotTable)
            ->whereIn($foreignKey, $parentIds)
            ->get();

        // Collect related IDs
        $relatedIds = [];
        $pivotMap = [];
        foreach ($pivotRows as $row) {
            $row = (array) $row;
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
        $rows = $this->connection->table($targetMetadata->table)
            ->whereIn($targetPkColumn, array_unique($relatedIds))
            ->get();

        // Index by PK
        $relatedByPk = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $pkValue = $row[$targetPkColumn] ?? null;
            if ($pkValue !== null) {
                $relatedByPk[$pkValue] = $this->hydrator->hydrate($relation->targetEntity, $row);
            }
        }

        // Assign to entities
        $reflection = new \ReflectionProperty($this->entityClass, $relationName);
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

    private function getPropertyForColumn(EntityMetadata $metadata, string $column): ?string
    {
        foreach ($metadata->fields as $property => $field) {
            if ($field->column === $column) {
                return $property;
            }
        }

        return null;
    }
}
