<?php

declare(strict_types=1);

namespace PureMapper\Query;

final readonly class WhereCondition
{
    public function __construct(
        public WhereBoolean $boolean,
        public string $column,
        public string $operator,
        public mixed $value,
    ) {}
}
