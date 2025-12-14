<?php

declare(strict_types=1);

namespace PureMapper\Type\Converter;

use PureMapper\Type\TypeConverter;

final class JsonConverter implements TypeConverter
{
    /**
     * @return array<mixed>|null
     */
    public function toPHP(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (\is_array($value)) {
            return $value;
        }

        return json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
    }

    public function toDatabase(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}
