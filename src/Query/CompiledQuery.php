<?php

declare(strict_types=1);

namespace PureMapper\Query;

/**
 * @phpstan-type ParamValue scalar|null
 */
final readonly class CompiledQuery
{
    /**
     * @param array<int, ParamValue> $params
     */
    public function __construct(
        public string $sql,
        public array $params,
    ) {}
}
