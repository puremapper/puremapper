<?php

declare(strict_types=1);

namespace PureMapper\Type;

interface TypeConverter
{
    /**
     * Convert a database value to PHP value.
     */
    public function toPHP(mixed $value): mixed;

    /**
     * Convert a PHP value to database value.
     */
    public function toDatabase(mixed $value): mixed;
}
