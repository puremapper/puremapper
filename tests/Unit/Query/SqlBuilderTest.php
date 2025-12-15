<?php

declare(strict_types=1);

namespace PureMapper\Tests\Unit\Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PureMapper\Query\DatabaseDriver;
use PureMapper\Query\SqlBuilder;
use RuntimeException;

final class SqlBuilderTest extends TestCase
{
    #[Test]
    public function selectAllFromTable(): void
    {
        $builder = new SqlBuilder(DatabaseDriver::MySQL);

        $query = $builder->table('users')->toSelect();

        $this->assertSame('SELECT * FROM `users`', $query->sql);
        $this->assertSame([], $query->params);
    }

    #[Test]
    public function selectSpecificColumns(): void
    {
        $builder = new SqlBuilder(DatabaseDriver::MySQL);

        $query = $builder
            ->table('users')
            ->select('id', 'name', 'email')
            ->toSelect();

        $this->assertSame('SELECT `id`, `name`, `email` FROM `users`', $query->sql);
        $this->assertSame([], $query->params);
    }

    #[Test]
    public function selectWithWhere(): void
    {
        $builder = new SqlBuilder(DatabaseDriver::MySQL);

        $query = $builder
            ->table('users')
            ->where('status', '=', 'active')
            ->toSelect();

        $this->assertSame('SELECT * FROM `users` WHERE `status` = ?', $query->sql);
        $this->assertSame(['active'], $query->params);
    }

    #[Test]
    public function selectWithMultipleWheres(): void
    {
        $builder = new SqlBuilder(DatabaseDriver::MySQL);

        $query = $builder
            ->table('users')
            ->where('status', '=', 'active')
            ->where('age', '>', 18)
            ->toSelect();

        $this->assertSame('SELECT * FROM `users` WHERE `status` = ? AND `age` > ?', $query->sql);
        $this->assertSame(['active', 18], $query->params);
    }

    #[Test]
    public function selectWithOrWhere(): void
    {
        $builder = new SqlBuilder(DatabaseDriver::MySQL);

        $query = $builder
            ->table('users')
            ->where('status', '=', 'active')
            ->orWhere('role', '=', 'admin')
            ->toSelect();

        $this->assertSame('SELECT * FROM `users` WHERE `status` = ? OR `role` = ?', $query->sql);
        $this->assertSame(['active', 'admin'], $query->params);
    }

    #[Test]
    public function selectWithWhereIn(): void
    {
        $builder = new SqlBuilder(DatabaseDriver::MySQL);

        $query = $builder
            ->table('users')
            ->whereIn('role', ['admin', 'editor', 'viewer'])
            ->toSelect();

        $this->assertSame('SELECT * FROM `users` WHERE `role` IN (?, ?, ?)', $query->sql);
        $this->assertSame(['admin', 'editor', 'viewer'], $query->params);
    }

    #[Test]
    public function whereInWithEmptyArrayThrowsException(): void
    {
        $builder = new SqlBuilder(DatabaseDriver::MySQL);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('whereIn() requires a non-empty array');

        $builder->table('users')->whereIn('role', []);
    }

    #[Test]
    public function selectWithOrderBy(): void
    {
        $builder = new SqlBuilder(DatabaseDriver::MySQL);

        $query = $builder
            ->table('users')
            ->orderBy('created_at', 'DESC')
            ->toSelect();

        $this->assertSame('SELECT * FROM `users` ORDER BY `created_at` DESC', $query->sql);
        $this->assertSame([], $query->params);
    }

    #[Test]
    public function selectWithMultipleOrderBy(): void
    {
        $builder = new SqlBuilder(DatabaseDriver::MySQL);

        $query = $builder
            ->table('users')
            ->orderBy('status', 'ASC')
            ->orderBy('created_at', 'DESC')
            ->toSelect();

        $this->assertSame('SELECT * FROM `users` ORDER BY `status` ASC, `created_at` DESC', $query->sql);
        $this->assertSame([], $query->params);
    }

    #[Test]
    public function selectWithLimitAndOffset(): void
    {
        $builder = new SqlBuilder(DatabaseDriver::MySQL);

        $query = $builder
            ->table('users')
            ->limit(10)
            ->offset(20)
            ->toSelect();

        $this->assertSame('SELECT * FROM `users` LIMIT 10 OFFSET 20', $query->sql);
        $this->assertSame([], $query->params);
    }

    #[Test]
    public function selectWithJoin(): void
    {
        $builder = new SqlBuilder(DatabaseDriver::MySQL);

        $query = $builder
            ->table('users')
            ->join('posts', 'users.id', '=', 'posts.user_id')
            ->toSelect();

        $this->assertSame(
            'SELECT * FROM `users` INNER JOIN `posts` ON `users`.`id` = `posts`.`user_id`',
            $query->sql,
        );
        $this->assertSame([], $query->params);
    }

    #[Test]
    public function selectWithLeftJoin(): void
    {
        $builder = new SqlBuilder(DatabaseDriver::MySQL);

        $query = $builder
            ->table('users')
            ->leftJoin('profiles', 'users.id', '=', 'profiles.user_id')
            ->toSelect();

        $this->assertSame(
            'SELECT * FROM `users` LEFT JOIN `profiles` ON `users`.`id` = `profiles`.`user_id`',
            $query->sql,
        );
        $this->assertSame([], $query->params);
    }

    #[Test]
    public function complexSelectQuery(): void
    {
        $builder = new SqlBuilder(DatabaseDriver::MySQL);

        $query = $builder
            ->table('users')
            ->select('id', 'name', 'email')
            ->where('status', '=', 'active')
            ->whereIn('role', ['admin', 'editor'])
            ->orderBy('created_at', 'DESC')
            ->limit(10)
            ->toSelect();

        $this->assertSame(
            'SELECT `id`, `name`, `email` FROM `users` WHERE `status` = ? AND `role` IN (?, ?) ORDER BY `created_at` DESC LIMIT 10',
            $query->sql,
        );
        $this->assertSame(['active', 'admin', 'editor'], $query->params);
    }

    #[Test]
    public function insert(): void
    {
        $builder = new SqlBuilder(DatabaseDriver::MySQL);

        $query = $builder
            ->table('users')
            ->toInsert(['name' => 'John', 'email' => 'john@example.com']);

        $this->assertSame(
            'INSERT INTO `users` (`name`, `email`) VALUES (?, ?)',
            $query->sql,
        );
        $this->assertSame(['John', 'john@example.com'], $query->params);
    }

    #[Test]
    public function update(): void
    {
        $builder = new SqlBuilder(DatabaseDriver::MySQL);

        $query = $builder
            ->table('users')
            ->where('id', '=', 1)
            ->toUpdate(['name' => 'Jane', 'email' => 'jane@example.com']);

        $this->assertSame(
            'UPDATE `users` SET `name` = ?, `email` = ? WHERE `id` = ?',
            $query->sql,
        );
        $this->assertSame(['Jane', 'jane@example.com', 1], $query->params);
    }

    #[Test]
    public function delete(): void
    {
        $builder = new SqlBuilder(DatabaseDriver::MySQL);

        $query = $builder
            ->table('users')
            ->where('id', '=', 1)
            ->toDelete();

        $this->assertSame('DELETE FROM `users` WHERE `id` = ?', $query->sql);
        $this->assertSame([1], $query->params);
    }

    #[Test]
    public function deleteWithMultipleConditions(): void
    {
        $builder = new SqlBuilder(DatabaseDriver::MySQL);

        $query = $builder
            ->table('users')
            ->where('status', '=', 'inactive')
            ->where('last_login', '<', '2020-01-01')
            ->toDelete();

        $this->assertSame(
            'DELETE FROM `users` WHERE `status` = ? AND `last_login` < ?',
            $query->sql,
        );
        $this->assertSame(['inactive', '2020-01-01'], $query->params);
    }

    #[Test]
    public function postgresqlIdentifierQuoting(): void
    {
        $builder = new SqlBuilder(DatabaseDriver::PostgreSQL);

        $query = $builder
            ->table('users')
            ->select('id', 'name')
            ->where('status', '=', 'active')
            ->toSelect();

        $this->assertSame('SELECT "id", "name" FROM "users" WHERE "status" = ?', $query->sql);
    }

    #[Test]
    public function sqliteIdentifierQuoting(): void
    {
        $builder = new SqlBuilder(DatabaseDriver::SQLite);

        $query = $builder
            ->table('users')
            ->select('id', 'name')
            ->where('status', '=', 'active')
            ->toSelect();

        $this->assertSame('SELECT "id", "name" FROM "users" WHERE "status" = ?', $query->sql);
    }

    #[Test]
    public function joinShouldQuoteTableAndColumnSeparately(): void
    {
        $builder = new SqlBuilder(DatabaseDriver::MySQL);

        $query = $builder
            ->table('users')
            ->join('posts', 'users.id', '=', 'posts.user_id')
            ->toSelect();

        // Correct: each part of table.column should be quoted separately
        $this->assertSame(
            'SELECT * FROM `users` INNER JOIN `posts` ON `users`.`id` = `posts`.`user_id`',
            $query->sql,
        );
    }

    #[Test]
    public function leftJoinShouldQuoteTableAndColumnSeparatelyPostgres(): void
    {
        $builder = new SqlBuilder(DatabaseDriver::PostgreSQL);

        $query = $builder
            ->table('orders')
            ->leftJoin('customers', 'orders.customer_id', '=', 'customers.id')
            ->toSelect();

        // Correct: PostgreSQL uses double quotes, each part quoted separately
        $this->assertSame(
            'SELECT * FROM "orders" LEFT JOIN "customers" ON "orders"."customer_id" = "customers"."id"',
            $query->sql,
        );
    }
}
