<?php

declare(strict_types=1);

namespace PureMapper\Exception;

final class HydrationException extends PureMapperException
{
    public static function forClass(string $class, string $reason): self
    {
        return new self(sprintf('Failed to hydrate entity "%s": %s', $class, $reason));
    }

    public static function forProperty(string $class, string $property, string $reason): self
    {
        return new self(sprintf('Failed to hydrate property "%s" of "%s": %s', $property, $class, $reason));
    }
}
