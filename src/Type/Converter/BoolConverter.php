<?php

declare(strict_types=1);

namespace PureMapper\Type\Converter;

use PureMapper\Type\TypeConverter;

final class BoolConverter implements TypeConverter
{
    public function toPHP(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        return (bool) $value;
    }

    public function toDatabase(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        return $value ? 1 : 0;
    }
}
