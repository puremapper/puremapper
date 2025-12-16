<?php

declare(strict_types=1);

namespace PureMapper\Query;

use InvalidArgumentException;
use RuntimeException;

final class SqlBuilder
{
    private const ALLOWED_OPERATORS = [
        '=', '!=', '<>', '<', '>', '<=', '>=',
        'LIKE', 'NOT LIKE',
    ];

    private const ALLOWED_DIRECTIONS = ['ASC', 'DESC'];

    private ?string $table = null;

    /** @var array<string> */
    private array $selects = [];

    /** @var array<WhereCondition|WhereNested> */
    private array $wheres = [];

    /** @var array<array{type: string, table: string, first: string, operator: string, second: string}> */
    private array $joins = [];

    /** @var array<array{column: string, direction: string}> */
    private array $orderBys = [];

    private ?int $limitValue = null;
    private ?int $offsetValue = null;

    public function __construct(
        private readonly DatabaseDriver $driver,
    ) {}

    public function table(string $table): self
    {
        $this->table = $table;

        return $this;
    }

    public function select(string ...$columns): self
    {
        $this->selects = array_merge($this->selects, $columns);

        return $this;
    }

    /**
     * @param string|callable(self): void $column
     */
    public function where(string|callable $column, ?string $operator = null, mixed $value = null): self
    {
        if (\is_callable($column)) {
            $nested = new self($this->driver);
            $column($nested);

            if (!empty($nested->wheres)) {
                $this->wheres[] = new WhereNested(WhereBoolean::And, $nested->wheres);
            }

            return $this;
        }

        $this->wheres[] = new WhereCondition(
            WhereBoolean::And,
            $column,
            $this->validateOperator($operator ?? '='),
            $value,
        );

        return $this;
    }

    /**
     * @param string|callable(self): void $column
     */
    public function orWhere(string|callable $column, ?string $operator = null, mixed $value = null): self
    {
        if (\is_callable($column)) {
            $nested = new self($this->driver);
            $column($nested);

            if (!empty($nested->wheres)) {
                $this->wheres[] = new WhereNested(WhereBoolean::Or, $nested->wheres);
            }

            return $this;
        }

        $this->wheres[] = new WhereCondition(
            WhereBoolean::Or,
            $column,
            $this->validateOperator($operator ?? '='),
            $value,
        );

        return $this;
    }

    /**
     * @param array<mixed> $values
     */
    public function whereIn(string $column, array $values): self
    {
        if (empty($values)) {
            throw new RuntimeException('whereIn() requires a non-empty array');
        }

        $this->wheres[] = new WhereCondition(
            WhereBoolean::And,
            $column,
            'IN',
            $values,
        );

        return $this;
    }

    public function whereNull(string $column): self
    {
        $this->wheres[] = new WhereCondition(
            WhereBoolean::And,
            $column,
            'IS NULL',
            null,
        );

        return $this;
    }

    public function whereNotNull(string $column): self
    {
        $this->wheres[] = new WhereCondition(
            WhereBoolean::And,
            $column,
            'IS NOT NULL',
            null,
        );

        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $direction = strtoupper($direction);

        if (!in_array($direction, self::ALLOWED_DIRECTIONS, true)) {
            throw new InvalidArgumentException("Invalid direction: {$direction}");
        }

        $this->orderBys[] = [
            'column' => $column,
            'direction' => $direction,
        ];

        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limitValue = $limit;

        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offsetValue = $offset;

        return $this;
    }

    public function join(string $table, string $first, string $operator, string $second): self
    {
        $this->joins[] = [
            'type' => 'INNER',
            'table' => $table,
            'first' => $first,
            'operator' => $this->validateOperator($operator),
            'second' => $second,
        ];

        return $this;
    }

    public function leftJoin(string $table, string $first, string $operator, string $second): self
    {
        $this->joins[] = [
            'type' => 'LEFT',
            'table' => $table,
            'first' => $first,
            'operator' => $this->validateOperator($operator),
            'second' => $second,
        ];

        return $this;
    }

    public function toSelect(): CompiledQuery
    {
        $params = [];

        // SELECT clause
        $columns = empty($this->selects)
            ? '*'
            : implode(', ', array_map(fn($col) => $this->driver->quoteIdentifier($col), $this->selects));

        $sql = "SELECT {$columns}";

        // FROM clause
        $sql .= ' FROM ' . $this->driver->quoteIdentifier($this->table ?? '');

        // JOIN clauses
        foreach ($this->joins as $join) {
            $sql .= " {$join['type']} JOIN " . $this->driver->quoteIdentifier($join['table']);
            $sql .= ' ON ' . $this->driver->quoteCompoundIdentifier($join['first']);
            $sql .= " {$join['operator']} " . $this->driver->quoteCompoundIdentifier($join['second']);
        }

        // WHERE clause
        $whereSql = $this->compileWheres($params);
        if ($whereSql !== '') {
            $sql .= ' WHERE ' . $whereSql;
        }

        // ORDER BY clause
        if (!empty($this->orderBys)) {
            $orderClauses = array_map(
                fn($order) => $this->driver->quoteIdentifier($order['column']) . ' ' . $order['direction'],
                $this->orderBys,
            );
            $sql .= ' ORDER BY ' . implode(', ', $orderClauses);
        }

        // LIMIT clause
        if ($this->limitValue !== null) {
            $sql .= ' LIMIT ' . $this->limitValue;
        }

        // OFFSET clause
        if ($this->offsetValue !== null) {
            $sql .= ' OFFSET ' . $this->offsetValue;
        }

        return new CompiledQuery($sql, $params);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function toInsert(array $data): CompiledQuery
    {
        $columns = array_keys($data);
        $values = array_values($data);

        $quotedColumns = array_map(
            fn($col) => $this->driver->quoteIdentifier($col),
            $columns,
        );

        $placeholders = array_fill(0, \count($columns), '?');

        $sql = 'INSERT INTO ' . $this->driver->quoteIdentifier($this->table ?? '');
        $sql .= ' (' . implode(', ', $quotedColumns) . ')';
        $sql .= ' VALUES (' . implode(', ', $placeholders) . ')';

        return new CompiledQuery($sql, $values);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function toUpdate(array $data): CompiledQuery
    {
        $params = [];
        $setClauses = [];

        foreach ($data as $column => $value) {
            $setClauses[] = $this->driver->quoteIdentifier($column) . ' = ?';
            $params[] = $value;
        }

        $sql = 'UPDATE ' . $this->driver->quoteIdentifier($this->table ?? '');
        $sql .= ' SET ' . implode(', ', $setClauses);

        // WHERE clause
        $whereSql = $this->compileWheres($params);
        if ($whereSql !== '') {
            $sql .= ' WHERE ' . $whereSql;
        }

        return new CompiledQuery($sql, $params);
    }

    public function toDelete(): CompiledQuery
    {
        $params = [];
        $sql = 'DELETE FROM ' . $this->driver->quoteIdentifier($this->table ?? '');

        // WHERE clause
        $whereSql = $this->compileWheres($params);
        if ($whereSql !== '') {
            $sql .= ' WHERE ' . $whereSql;
        }

        return new CompiledQuery($sql, $params);
    }

    /**
     * @param array<mixed> $params
     */
    private function compileWheres(array &$params): string
    {
        return $this->compileWheresRecursive($this->wheres, $params);
    }

    /**
     * @param array<WhereCondition|WhereNested> $wheres
     * @param array<mixed> $params
     */
    private function compileWheresRecursive(array $wheres, array &$params): string
    {
        if (empty($wheres)) {
            return '';
        }

        $clauses = [];

        foreach ($wheres as $i => $where) {
            $prefix = ($i > 0) ? ($where->boolean->value . ' ') : '';

            if ($where instanceof WhereNested) {
                $nestedSql = $this->compileWheresRecursive($where->clauses, $params);

                if ($nestedSql !== '') {
                    $clauses[] = $prefix . '(' . $nestedSql . ')';
                }

                continue;
            }

            $column = $this->driver->quoteIdentifier($where->column);

            if ($where->operator === 'IN') {
                /** @var array<mixed> $values */
                $values = $where->value;
                $placeholders = array_fill(0, \count($values), '?');
                $clauses[] = $prefix . $column . ' IN (' . implode(', ', $placeholders) . ')';

                foreach ($values as $val) {
                    $params[] = $val;
                }
            } elseif ($where->operator === 'IS NULL' || $where->operator === 'IS NOT NULL') {
                $clauses[] = $prefix . $column . ' ' . $where->operator;
            } else {
                $clauses[] = $prefix . $column . ' ' . $where->operator . ' ?';
                $params[] = $where->value;
            }
        }

        return implode(' ', $clauses);
    }

    private function validateOperator(string $operator): string
    {
        $operator = strtoupper(trim($operator));

        if (!in_array($operator, self::ALLOWED_OPERATORS, true)) {
            throw new InvalidArgumentException("Invalid operator: {$operator}");
        }

        return $operator;
    }
}
