<?php

declare(strict_types=1);

namespace PureMapper\Exception;

final class TypeNotFoundException extends PureMapperException
{
    public static function forType(string $type): self
    {
        return new self(sprintf('No type converter found for type "%s".', $type));
    }
}
