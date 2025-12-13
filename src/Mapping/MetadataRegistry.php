<?php

declare(strict_types=1);

namespace PureMapper\Mapping;

use PureMapper\Exception\MetadataNotFoundException;

final class MetadataRegistry
{
    /**
     * @var array<class-string, EntityMetadata>
     */
    private array $metadata = [];

    public function register(EntityMetadata $metadata): void
    {
        $this->metadata[$metadata->entityClass] = $metadata;
    }

    /**
     * @param class-string $class
     * @throws MetadataNotFoundException
     */
    public function get(string $class): EntityMetadata
    {
        if (!isset($this->metadata[$class])) {
            throw MetadataNotFoundException::forClass($class);
        }

        return $this->metadata[$class];
    }

    /**
     * @param class-string $class
     */
    public function has(string $class): bool
    {
        return isset($this->metadata[$class]);
    }
}
