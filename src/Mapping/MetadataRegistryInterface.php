<?php

declare(strict_types=1);

namespace PureMapper\Mapping;

use PureMapper\Exception\MetadataNotFoundException;

interface MetadataRegistryInterface
{
    public function register(EntityMetadata $metadata): void;

    /**
     * @param class-string $class
     * @throws MetadataNotFoundException
     */
    public function get(string $class): EntityMetadata;

    /**
     * @param class-string $class
     */
    public function has(string $class): bool;

    /**
     * @return array<class-string, EntityMetadata>
     */
    public function all(): array;
}
