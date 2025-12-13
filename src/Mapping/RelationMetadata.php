<?php

declare(strict_types=1);

namespace PureMapper\Mapping;

final readonly class RelationMetadata
{
    public function __construct(
        public string $property,
        public RelationType $type,
        public string $targetEntity,
        public string $foreignKey,
        public ?string $pivotTable = null,
        public ?string $relatedKey = null,
    ) {
    }
}
