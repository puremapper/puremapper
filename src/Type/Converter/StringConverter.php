<?php

declare(strict_types=1);

namespace PureMapper\Type\Converter;

use PureMapper\Type\TypeConverter;

final class StringConverter implements TypeConverter
{
    public function toPHP(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return (string) $value;
    }

    public function toDatabase(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return (string) $value;
    }
}
