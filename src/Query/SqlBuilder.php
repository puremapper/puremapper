<?php

declare(strict_types=1);

namespace PureMapper\Query;

use RuntimeException;

final class SqlBuilder
{
    private ?string $table = null;

    /** @var array<string> */
    private array $selects = [];

    /** @var array<array{type: string, column: string, operator: string, value: mixed}> */
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

    public function where(string $column, string $operator, mixed $value): self
    {
        $this->wheres[] = [
            'type' => 'and',
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
        ];

        return $this;
    }

    public function orWhere(string $column, string $operator, mixed $value): self
    {
        $this->wheres[] = [
            'type' => 'or',
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
        ];

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

        $this->wheres[] = [
            'type' => 'and',
            'column' => $column,
            'operator' => 'IN',
            'value' => $values,
        ];

        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $this->orderBys[] = [
            'column' => $column,
            'direction' => strtoupper($direction),
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
            'operator' => $operator,
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
            'operator' => $operator,
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
        if (empty($this->wheres)) {
            return '';
        }

        $clauses = [];

        foreach ($this->wheres as $i => $where) {
            $column = $this->driver->quoteIdentifier($where['column']);
            $prefix = ($i > 0) ? (strtoupper($where['type']) . ' ') : '';

            if ($where['operator'] === 'IN') {
                /** @var array<mixed> $values */
                $values = $where['value'];
                $placeholders = array_fill(0, \count($values), '?');
                $clauses[] = $prefix . $column . ' IN (' . implode(', ', $placeholders) . ')';

                foreach ($values as $val) {
                    $params[] = $val;
                }
            } else {
                $clauses[] = $prefix . $column . ' ' . $where['operator'] . ' ?';
                $params[] = $where['value'];
            }
        }

        return implode(' ', $clauses);
    }
}
