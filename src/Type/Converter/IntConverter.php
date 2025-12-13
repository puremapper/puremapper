<?php

declare(strict_types=1);

namespace PureMapper\Type\Converter;

use PureMapper\Type\TypeConverter;

final class IntConverter implements TypeConverter
{
    public function toPHP(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        return (int) $value;
    }

    public function toDatabase(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        return (int) $value;
    }
}
