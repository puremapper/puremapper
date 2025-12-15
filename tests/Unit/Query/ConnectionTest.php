<?php

declare(strict_types=1);

namespace PureMapper\Tests\Unit\Query;

use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PureMapper\Query\CompiledQuery;
use PureMapper\Query\Connection;
use PureMapper\Query\DatabaseDriver;
use PureMapper\Query\SqlBuilder;
use RuntimeException;

final class ConnectionTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $this->connection = new Connection($pdo, DatabaseDriver::SQLite);

        // Create test table
        $this->connection->statement('
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT NOT NULL
            )
        ');
    }

    #[Test]
    public function selectReturnsArrayOfAssocArrays(): void
    {
        // Insert test data
        $this->connection->insert(new CompiledQuery(
            'INSERT INTO "users" ("name", "email") VALUES (?, ?)',
            ['John', 'john@example.com'],
        ));
        $this->connection->insert(new CompiledQuery(
            'INSERT INTO "users" ("name", "email") VALUES (?, ?)',
            ['Jane', 'jane@example.com'],
        ));

        $result = $this->connection->select(new CompiledQuery(
            'SELECT * FROM "users" ORDER BY "id"',
            [],
        ));

        $this->assertCount(2, $result);
        $this->assertSame('John', $result[0]['name']);
        $this->assertSame('jane@example.com', $result[1]['email']);
    }

    #[Test]
    public function insertReturnsLastInsertId(): void
    {
        $id = $this->connection->insert(new CompiledQuery(
            'INSERT INTO "users" ("name", "email") VALUES (?, ?)',
            ['John', 'john@example.com'],
        ));

        $this->assertSame('1', $id);

        $id2 = $this->connection->insert(new CompiledQuery(
            'INSERT INTO "users" ("name", "email") VALUES (?, ?)',
            ['Jane', 'jane@example.com'],
        ));

        $this->assertSame('2', $id2);
    }

    #[Test]
    public function executeReturnsAffectedRows(): void
    {
        $this->connection->insert(new CompiledQuery(
            'INSERT INTO "users" ("name", "email") VALUES (?, ?)',
            ['John', 'john@example.com'],
        ));
        $this->connection->insert(new CompiledQuery(
            'INSERT INTO "users" ("name", "email") VALUES (?, ?)',
            ['Jane', 'jane@example.com'],
        ));

        $affected = $this->connection->execute(new CompiledQuery(
            'UPDATE "users" SET "name" = ?',
            ['Updated'],
        ));

        $this->assertSame(2, $affected);
    }

    #[Test]
    public function tableReturnsSqlBuilder(): void
    {
        $builder = $this->connection->table('users');

        $this->assertInstanceOf(SqlBuilder::class, $builder);
    }

    #[Test]
    public function transactionCommit(): void
    {
        $this->connection->beginTransaction();

        $this->connection->insert(new CompiledQuery(
            'INSERT INTO "users" ("name", "email") VALUES (?, ?)',
            ['John', 'john@example.com'],
        ));

        $this->connection->commit();

        $result = $this->connection->select(new CompiledQuery(
            'SELECT COUNT(*) as count FROM "users"',
            [],
        ));

        $this->assertSame(1, (int) $result[0]['count']);
    }

    #[Test]
    public function transactionRollback(): void
    {
        $this->connection->beginTransaction();

        $this->connection->insert(new CompiledQuery(
            'INSERT INTO "users" ("name", "email") VALUES (?, ?)',
            ['John', 'john@example.com'],
        ));

        $this->connection->rollBack();

        $result = $this->connection->select(new CompiledQuery(
            'SELECT COUNT(*) as count FROM "users"',
            [],
        ));

        $this->assertSame(0, (int) $result[0]['count']);
    }

    #[Test]
    public function beginTransactionTwiceThrowsException(): void
    {
        $this->connection->beginTransaction();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Transaction already started');

        $this->connection->beginTransaction();
    }

    #[Test]
    public function commitWithoutTransactionThrowsException(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No active transaction');

        $this->connection->commit();
    }

    #[Test]
    public function rollbackWithoutTransactionThrowsException(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No active transaction');

        $this->connection->rollBack();
    }

    #[Test]
    public function getPdoReturnsPdoInstance(): void
    {
        $pdo = $this->connection->getPdo();

        $this->assertInstanceOf(PDO::class, $pdo);
    }

    #[Test]
    public function getDriverReturnsDriver(): void
    {
        $driver = $this->connection->getDriver();

        $this->assertSame(DatabaseDriver::SQLite, $driver);
    }

    #[Test]
    public function integrationWithSqlBuilder(): void
    {
        // Insert using SqlBuilder
        $insertQuery = $this->connection
            ->table('users')
            ->toInsert(['name' => 'John', 'email' => 'john@example.com']);

        $id = $this->connection->insert($insertQuery);
        $this->assertSame('1', $id);

        // Select using SqlBuilder
        $selectQuery = $this->connection
            ->table('users')
            ->select('id', 'name', 'email')
            ->where('id', '=', 1)
            ->toSelect();

        $result = $this->connection->select($selectQuery);

        $this->assertCount(1, $result);
        $this->assertSame('John', $result[0]['name']);
        $this->assertSame('john@example.com', $result[0]['email']);

        // Update using SqlBuilder
        $updateQuery = $this->connection
            ->table('users')
            ->where('id', '=', 1)
            ->toUpdate(['name' => 'Jane']);

        $affected = $this->connection->execute($updateQuery);
        $this->assertSame(1, $affected);

        // Verify update
        $result = $this->connection->select($selectQuery);
        $this->assertSame('Jane', $result[0]['name']);

        // Delete using SqlBuilder
        $deleteQuery = $this->connection
            ->table('users')
            ->where('id', '=', 1)
            ->toDelete();

        $affected = $this->connection->execute($deleteQuery);
        $this->assertSame(1, $affected);

        // Verify delete
        $result = $this->connection->select($selectQuery);
        $this->assertCount(0, $result);
    }
}
