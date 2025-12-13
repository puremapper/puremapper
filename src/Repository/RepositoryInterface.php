<?php

declare(strict_types=1);

namespace PureMapper\Repository;

/**
 * Repository interface for entity persistence.
 *
 * @template T of object
 */
interface RepositoryInterface
{
    /**
     * Find an entity by its primary key.
     *
     * @param int|string|array<string, mixed> $id
     * @return T|null
     */
    public function find(int|string|array $id): ?object;

    /**
     * Find all entities.
     *
     * @return array<T>
     */
    public function findAll(): array;

    /**
     * Find entities by given criteria.
     *
     * @param array<string, mixed> $criteria
     * @return array<T>
     */
    public function findBy(array $criteria): array;
}
