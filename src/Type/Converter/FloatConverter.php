<?php

declare(strict_types=1);

namespace PureMapper\Type\Converter;

use PureMapper\Type\TypeConverter;

final class FloatConverter implements TypeConverter
{
    public function toPHP(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        return (float) $value;
    }

    public function toDatabase(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        return (float) $value;
    }
}
