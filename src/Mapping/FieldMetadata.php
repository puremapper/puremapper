<?php

declare(strict_types=1);

namespace PureMapper\Mapping;

final readonly class FieldMetadata
{
    public function __construct(
        public string $property,
        public string $type,
        public string $column,
    ) {
    }
}
