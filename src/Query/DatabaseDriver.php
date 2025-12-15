<?php

declare(strict_types=1);

namespace PureMapper\Query;

enum DatabaseDriver: string
{
    case MySQL = 'mysql';
    case PostgreSQL = 'pgsql';
    case SQLite = 'sqlite';

    public function quoteIdentifier(string $identifier): string
    {
        return match ($this) {
            self::MySQL => "`{$identifier}`",
            self::PostgreSQL, self::SQLite => "\"{$identifier}\"",
        };
    }

    /**
     * Quote a compound identifier (e.g., "table.column" -> "`table`.`column`").
     */
    public function quoteCompoundIdentifier(string $identifier): string
    {
        if (!str_contains($identifier, '.')) {
            return $this->quoteIdentifier($identifier);
        }

        $parts = explode('.', $identifier);

        return implode('.', array_map(
            fn(string $part) => $this->quoteIdentifier($part),
            $parts,
        ));
    }
}
