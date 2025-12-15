<?php

declare(strict_types=1);

namespace PureMapper\Persistence;

use PureMapper\Hydration\Hydrator;
use PureMapper\Mapping\MetadataRegistryInterface;
use PureMapper\Query\Connection;
use RuntimeException;
use SplObjectStorage;
use Throwable;

final class UnitOfWork
{
    private IdentityMap $identityMap;

    /**
     * @var SplObjectStorage<object, EntityState>
     */
    private SplObjectStorage $entityStates;

    /**
     * @var array<int, object>
     */
    private array $scheduledInserts = [];

    /**
     * @var array<int, object>
     */
    private array $scheduledUpdates = [];

    /**
     * @var array<int, object>
     */
    private array $scheduledDeletes = [];

    private bool $autoTransaction = true;

    public function __construct(
        private readonly Connection $connection,
        private readonly MetadataRegistryInterface $metadataRegistry,
        private readonly Hydrator $hydrator,
    ) {
        $this->identityMap = new IdentityMap();
        $this->entityStates = new SplObjectStorage();
    }

    public function getIdentityMap(): IdentityMap
    {
        return $this->identityMap;
    }

    /**
     * Mark an entity for insertion.
     */
    public function persist(object $entity): void
    {
        $id = $this->hydrator->getIdentifier($entity);

        if ($id !== null && $this->entityStates->offsetExists($entity)) {
            // Already tracked
            return;
        }

        if ($id === null || $this->isNewEntity($entity)) {
            $oid = \spl_object_id($entity);
            $this->scheduledInserts[$oid] = $entity;
            $this->entityStates[$entity] = EntityState::New;
        } else {
            // Entity with ID - add to identity map as managed
            $this->registerManaged($entity, $id);
        }
    }

    /**
     * Mark an entity as dirty (needs UPDATE).
     */
    public function markDirty(object $entity): void
    {
        if (!$this->entityStates->offsetExists($entity)) {
            throw new RuntimeException('Entity is not managed.');
        }

        $state = $this->entityStates[$entity];

        if ($state === EntityState::New) {
            return; // Will be inserted anyway
        }

        if ($state === EntityState::Removed) {
            throw new RuntimeException('Cannot modify a removed entity.');
        }

        $oid = \spl_object_id($entity);
        $this->scheduledUpdates[$oid] = $entity;
        $this->entityStates[$entity] = EntityState::Dirty;
    }

    /**
     * Mark an entity for removal.
     */
    public function remove(object $entity): void
    {
        if (!$this->entityStates->offsetExists($entity)) {
            throw new RuntimeException('Entity is not managed.');
        }

        $state = $this->entityStates[$entity];

        $oid = \spl_object_id($entity);

        if ($state === EntityState::New) {
            // Not yet persisted, just remove from inserts
            unset($this->scheduledInserts[$oid]);
            $this->entityStates->offsetUnset($entity);
            return;
        }

        $this->scheduledDeletes[$oid] = $entity;
        $this->entityStates[$entity] = EntityState::Removed;
    }

    /**
     * Commit all changes to the database.
     */
    public function commit(): void
    {
        if ($this->autoTransaction) {
            $this->connection->beginTransaction();
        }

        try {
            $this->executeInserts();
            $this->executeUpdates();
            $this->executeDeletes();

            if ($this->autoTransaction) {
                $this->connection->commit();
            }

            $this->postCommit();
        } catch (Throwable $e) {
            if ($this->autoTransaction) {
                $this->connection->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Clear the Unit of Work state.
     */
    public function clear(): void
    {
        $this->identityMap->clear();
        $this->entityStates = new SplObjectStorage();
        $this->scheduledInserts = [];
        $this->scheduledUpdates = [];
        $this->scheduledDeletes = [];
    }

    public function setAutoTransaction(bool $auto): void
    {
        $this->autoTransaction = $auto;
    }

    /**
     * Register an entity as managed (loaded from database).
     *
     * @param class-string|null $class
     */
    public function registerManaged(object $entity, mixed $id, ?string $class = null): void
    {
        $class = $class ?? $entity::class;
        $this->identityMap->set($class, $id, $entity);
        $this->entityStates[$entity] = EntityState::Managed;
    }

    public function getState(object $entity): ?EntityState
    {
        return $this->entityStates[$entity] ?? null;
    }

    private function isNewEntity(object $entity): bool
    {
        $id = $this->hydrator->getIdentifier($entity);

        // If ID is null or 0, consider it new
        if ($id === null) {
            return true;
        }

        if (\is_array($id)) {
            return array_filter($id, fn($v) => $v !== null && $v !== 0) === [];
        }

        return $id === 0;
    }

    private function executeInserts(): void
    {
        foreach ($this->scheduledInserts as $entity) {
            $class = $entity::class;
            $metadata = $this->metadataRegistry->get($class);
            $data = $this->hydrator->extract($entity);

            // Remove null primary key for auto-increment
            $pk = \is_array($metadata->primaryKey)
                ? $metadata->primaryKey
                : [$metadata->primaryKey];

            foreach ($pk as $key) {
                $column = $metadata->fields[$key]->column ?? $key;
                if ($data[$column] === null || $data[$column] === 0) {
                    unset($data[$column]);
                }
            }

            $query = $this->connection->table($metadata->table)->toInsert($data);

            // Get last insert ID for single auto-increment keys
            if (!\is_array($metadata->primaryKey)) {
                $column = $metadata->fields[$metadata->primaryKey]->column ?? $metadata->primaryKey;
                if (!isset($data[$column])) {
                    $id = $this->connection->insert($query);
                    $this->hydrator->setIdentifier($entity, (int) $id);
                } else {
                    $this->connection->execute($query);
                }
            } else {
                $this->connection->execute($query);
            }
        }
    }

    private function executeUpdates(): void
    {
        foreach ($this->scheduledUpdates as $entity) {
            $class = $entity::class;
            $metadata = $this->metadataRegistry->get($class);
            $data = $this->hydrator->extract($entity);
            $id = $this->hydrator->getIdentifier($entity);

            // Build WHERE clause for primary key
            $pk = \is_array($metadata->primaryKey)
                ? $metadata->primaryKey
                : [$metadata->primaryKey];

            $builder = $this->connection->table($metadata->table);

            foreach ($pk as $key) {
                $column = $metadata->fields[$key]->column ?? $key;
                $value = \is_array($id) ? $id[$key] : $id;
                $builder->where($column, '=', $value);

                // Remove PK from update data
                unset($data[$column]);
            }

            $query = $builder->toUpdate($data);
            $this->connection->execute($query);
        }
    }

    private function executeDeletes(): void
    {
        foreach ($this->scheduledDeletes as $entity) {
            $class = $entity::class;
            $metadata = $this->metadataRegistry->get($class);
            $id = $this->hydrator->getIdentifier($entity);

            $pk = \is_array($metadata->primaryKey)
                ? $metadata->primaryKey
                : [$metadata->primaryKey];

            $builder = $this->connection->table($metadata->table);

            foreach ($pk as $key) {
                $column = $metadata->fields[$key]->column ?? $key;
                $value = \is_array($id) ? $id[$key] : $id;
                $builder->where($column, '=', $value);
            }

            $query = $builder->toDelete();
            $this->connection->execute($query);
        }
    }

    private function postCommit(): void
    {
        // Move inserted entities to managed state
        foreach ($this->scheduledInserts as $entity) {
            $id = $this->hydrator->getIdentifier($entity);
            $this->registerManaged($entity, $id);
        }

        // Move updated entities back to managed state
        foreach ($this->scheduledUpdates as $entity) {
            $this->entityStates[$entity] = EntityState::Managed;
        }

        // Remove deleted entities from identity map
        foreach ($this->scheduledDeletes as $entity) {
            $class = $entity::class;
            $id = $this->hydrator->getIdentifier($entity);
            $this->identityMap->remove($class, $id);
            $this->entityStates->offsetUnset($entity);
        }

        $this->scheduledInserts = [];
        $this->scheduledUpdates = [];
        $this->scheduledDeletes = [];
    }
}
