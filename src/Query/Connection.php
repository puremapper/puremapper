<?php

declare(strict_types=1);

namespace PureMapper\Query;

use PDO;
use RuntimeException;

final class Connection
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly DatabaseDriver $driver,
    ) {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    /**
     * Execute SELECT query and return all rows.
     *
     * @return array<int, array<string, mixed>>
     */
    public function select(CompiledQuery $query): array
    {
        $stmt = $this->pdo->prepare($query->sql);
        $stmt->execute($query->params);

        /** @var array<int, array<string, mixed>> */
        return $stmt->fetchAll();
    }

    /**
     * Execute INSERT/UPDATE/DELETE and return affected rows.
     */
    public function execute(CompiledQuery $query): int
    {
        $stmt = $this->pdo->prepare($query->sql);
        $stmt->execute($query->params);

        return $stmt->rowCount();
    }

    /**
     * Execute INSERT and return last insert ID.
     */
    public function insert(CompiledQuery $query): string
    {
        $this->execute($query);

        $id = $this->pdo->lastInsertId();

        return $id !== false ? $id : '0';
    }

    /**
     * Create a new query builder for the given table.
     */
    public function table(string $table): SqlBuilder
    {
        return (new SqlBuilder($this->driver))->table($table);
    }

    /**
     * Get the database driver.
     */
    public function getDriver(): DatabaseDriver
    {
        return $this->driver;
    }

    /**
     * Begin a database transaction.
     */
    public function beginTransaction(): void
    {
        if ($this->pdo->inTransaction()) {
            throw new RuntimeException('Transaction already started');
        }

        $this->pdo->beginTransaction();
    }

    /**
     * Commit the active transaction.
     */
    public function commit(): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('No active transaction');
        }

        $this->pdo->commit();
    }

    /**
     * Roll back the active transaction.
     */
    public function rollBack(): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('No active transaction');
        }

        $this->pdo->rollBack();
    }

    /**
     * Get the underlying PDO instance.
     */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Execute a raw SQL statement (for DDL queries).
     */
    public function statement(string $sql): bool
    {
        return $this->pdo->exec($sql) !== false;
    }
}
