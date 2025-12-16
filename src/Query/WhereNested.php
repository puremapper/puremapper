<?php

declare(strict_types=1);

namespace PureMapper\Query;

final readonly class WhereNested
{
    /**
     * @param array<WhereCondition|WhereNested> $clauses
     */
    public function __construct(
        public WhereBoolean $boolean,
        public array $clauses,
    ) {}
}
