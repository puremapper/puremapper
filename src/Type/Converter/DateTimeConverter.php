<?php

declare(strict_types=1);

namespace PureMapper\Type\Converter;

use DateTimeImmutable;
use PureMapper\Type\TypeConverter;

final class DateTimeConverter implements TypeConverter
{
    private const FORMAT = 'Y-m-d H:i:s';

    public function toPHP(mixed $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        $datetime = DateTimeImmutable::createFromFormat(self::FORMAT, (string) $value);

        if ($datetime === false) {
            // Try ISO 8601 format as fallback
            $datetime = new DateTimeImmutable((string) $value);
        }

        return $datetime;
    }

    public function toDatabase(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeImmutable) {
            return $value->format(self::FORMAT);
        }

        return (string) $value;
    }
}
