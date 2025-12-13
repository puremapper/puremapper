<?php

declare(strict_types=1);

namespace PureMapper\Type\Converter;

use DateTimeImmutable;
use PureMapper\Type\TypeConverter;

final class DateConverter implements TypeConverter
{
    private const FORMAT = 'Y-m-d';

    public function toPHP(mixed $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        $date = DateTimeImmutable::createFromFormat(self::FORMAT, (string) $value);

        if ($date === false) {
            $date = new DateTimeImmutable((string) $value);
        }

        return $date->setTime(0, 0);
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
