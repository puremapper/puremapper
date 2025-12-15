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
}
