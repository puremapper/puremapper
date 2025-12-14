<?php

declare(strict_types=1);

namespace PureMapper\Exception;

final class EntityNotFoundException extends PureMapperException
{
    public static function forId(string $class, mixed $id): self
    {
        $idString = \is_array($id) ? json_encode($id) : (string) $id;

        return new self(\sprintf('Entity "%s" with ID %s not found.', $class, $idString));
    }
}
