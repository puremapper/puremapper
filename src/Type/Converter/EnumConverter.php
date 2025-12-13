<?php

declare(strict_types=1);

namespace PureMapper\Type\Converter;

use BackedEnum;
use PureMapper\Type\TypeConverter;

final class EnumConverter implements TypeConverter
{
    /**
     * @param class-string<BackedEnum> $enumClass
     */
    public function __construct(
        private readonly string $enumClass,
    ) {
    }

    public function toPHP(mixed $value): ?BackedEnum
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof BackedEnum) {
            return $value;
        }

        return $this->enumClass::from($value);
    }

    public function toDatabase(mixed $value): string|int|null
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        return $value;
    }
}
