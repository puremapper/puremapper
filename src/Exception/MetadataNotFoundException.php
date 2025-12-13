<?php

declare(strict_types=1);

namespace PureMapper\Exception;

final class MetadataNotFoundException extends PureMapperException
{
    public static function forClass(string $class): self
    {
        return new self(sprintf('No metadata found for entity class "%s".', $class));
    }
}
