<?php

declare(strict_types=1);

namespace PureMapper;

use PureMapper\Hydration\Hydrator;
use PureMapper\Mapping\MetadataRegistryInterface;
use PureMapper\Persistence\UnitOfWork;
use PureMapper\Query\Connection;
use PureMapper\Query\EntityQuery;
use PureMapper\Type\TypeRegistry;

final class EntityManager
{
    private readonly Hydrator $hydrator;
    private readonly UnitOfWork $unitOfWork;

    public function __construct(
        private readonly Connection $connection,
        private readonly MetadataRegistryInterface $metadataRegistry,
        private readonly TypeRegistry $typeRegistry,
    ) {
        $this->hydrator = new Hydrator($this->metadataRegistry, $this->typeRegistry);
        $this->unitOfWork = new UnitOfWork($this->connection, $this->metadataRegistry, $this->hydrator);
    }

    /**
     * Create a query builder for the given entity class.
     *
     * @template T of object
     * @param class-string<T> $entityClass
     * @return EntityQuery<T>
     */
    public function query(string $entityClass): EntityQuery
    {
        return new EntityQuery(
            $entityClass,
            $this->connection,
            $this->metadataRegistry,
            $this->hydrator,
            $this->unitOfWork,
        );
    }

    /**
     * Mark an entity for insertion.
     */
    public function persist(object $entity): void
    {
        $this->unitOfWork->persist($entity);
    }

    /**
     * Mark an entity as dirty (needs UPDATE).
     */
    public function markDirty(object $entity): void
    {
        $this->unitOfWork->markDirty($entity);
    }

    /**
     * Mark an entity for removal.
     */
    public function remove(object $entity): void
    {
        $this->unitOfWork->remove($entity);
    }

    /**
     * Commit all changes to the database.
     */
    public function commit(): void
    {
        $this->unitOfWork->commit();
    }

    /**
     * Clear the entity manager state.
     */
    public function clear(): void
    {
        $this->unitOfWork->clear();
    }

    /**
     * Get the underlying Unit of Work.
     */
    public function getUnitOfWork(): UnitOfWork
    {
        return $this->unitOfWork;
    }

    /**
     * Get the metadata registry.
     */
    public function getMetadataRegistry(): MetadataRegistryInterface
    {
        return $this->metadataRegistry;
    }

    /**
     * Get the type registry.
     */
    public function getTypeRegistry(): TypeRegistry
    {
        return $this->typeRegistry;
    }

    /**
     * Get the hydrator.
     */
    public function getHydrator(): Hydrator
    {
        return $this->hydrator;
    }

    /**
     * Get the database connection.
     */
    public function getConnection(): Connection
    {
        return $this->connection;
    }
}
