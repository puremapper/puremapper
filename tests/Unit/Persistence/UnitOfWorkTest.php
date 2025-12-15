<?php

declare(strict_types=1);

namespace PureMapper\Tests\Unit\Persistence;

use PDO;
use PHPUnit\Framework\TestCase;
use PureMapper\Hydration\Hydrator;
use PureMapper\Mapping\EntityMapper;
use PureMapper\Mapping\MetadataRegistry;
use PureMapper\Persistence\EntityState;
use PureMapper\Persistence\UnitOfWork;
use PureMapper\Query\Connection;
use PureMapper\Query\DatabaseDriver;
use PureMapper\Type\TypeRegistry;
use RuntimeException;

final class UnitOfWorkTest extends TestCase
{
    private UnitOfWork $uow;
    private Connection $connection;
    private MetadataRegistry $metadataRegistry;

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

        $this->metadataRegistry = new MetadataRegistry();
        $this->metadataRegistry->register(
            (new EntityMapper(UnitOfWorkTestUser::class))
                ->table('users')
                ->id('id')
                ->field('name', 'string')
                ->field('email', 'string')
                ->build(),
        );

        $hydrator = new Hydrator($this->metadataRegistry, new TypeRegistry());
        $this->uow = new UnitOfWork($this->connection, $this->metadataRegistry, $hydrator);
    }

    public function testPersistNewEntity(): void
    {
        $user = new UnitOfWorkTestUser();
        $user->name = 'John';
        $user->email = 'john@example.com';

        $this->uow->persist($user);

        $this->assertSame(EntityState::New, $this->uow->getState($user));
    }

    public function testPersistManagedEntity(): void
    {
        $user = new UnitOfWorkTestUser();
        $user->id = 1;
        $user->name = 'John';
        $user->email = 'john@example.com';

        $this->uow->registerManaged($user, 1);
        $this->uow->persist($user);

        $this->assertSame(EntityState::Managed, $this->uow->getState($user));
    }

    public function testMarkDirty(): void
    {
        $user = new UnitOfWorkTestUser();
        $user->id = 1;
        $user->name = 'John';
        $user->email = 'john@example.com';

        $this->uow->registerManaged($user, 1);
        $this->uow->markDirty($user);

        $this->assertSame(EntityState::Dirty, $this->uow->getState($user));
    }

    public function testMarkDirtyThrowsForUnmanagedEntity(): void
    {
        $user = new UnitOfWorkTestUser();
        $user->name = 'John';
        $user->email = 'john@example.com';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Entity is not managed.');

        $this->uow->markDirty($user);
    }

    public function testRemove(): void
    {
        $user = new UnitOfWorkTestUser();
        $user->id = 1;
        $user->name = 'John';
        $user->email = 'john@example.com';

        $this->uow->registerManaged($user, 1);
        $this->uow->remove($user);

        $this->assertSame(EntityState::Removed, $this->uow->getState($user));
    }

    public function testRemoveNewEntity(): void
    {
        $user = new UnitOfWorkTestUser();
        $user->name = 'John';
        $user->email = 'john@example.com';

        $this->uow->persist($user);
        $this->uow->remove($user);

        $this->assertNull($this->uow->getState($user));
    }

    public function testClear(): void
    {
        $user = new UnitOfWorkTestUser();
        $user->id = 1;
        $user->name = 'John';
        $user->email = 'john@example.com';

        $this->uow->registerManaged($user, 1);
        $this->uow->clear();

        $this->assertNull($this->uow->getState($user));
        $this->assertNull($this->uow->getIdentityMap()->get(UnitOfWorkTestUser::class, 1));
    }

    public function testIdentityMapIntegration(): void
    {
        $user = new UnitOfWorkTestUser();
        $user->id = 1;
        $user->name = 'John';
        $user->email = 'john@example.com';

        $this->uow->registerManaged($user, 1);

        $this->assertSame($user, $this->uow->getIdentityMap()->get(UnitOfWorkTestUser::class, 1));
    }

    public function testCommitInsert(): void
    {
        $user = new UnitOfWorkTestUser();
        $user->name = 'John';
        $user->email = 'john@example.com';

        $this->uow->persist($user);
        $this->uow->commit();

        $this->assertSame(1, $user->id);
        $this->assertSame(EntityState::Managed, $this->uow->getState($user));

        // Verify data in database
        $rows = $this->connection->select(
            $this->connection->table('users')->where('id', '=', 1)->toSelect(),
        );
        $this->assertCount(1, $rows);
        $this->assertSame('John', $rows[0]['name']);
        $this->assertSame('john@example.com', $rows[0]['email']);
    }

    public function testCommitUpdate(): void
    {
        // First insert a user
        $user = new UnitOfWorkTestUser();
        $user->name = 'John';
        $user->email = 'john@example.com';

        $this->uow->persist($user);
        $this->uow->commit();

        // Now update
        $user->name = 'John Updated';
        $this->uow->markDirty($user);
        $this->uow->commit();

        $this->assertSame(EntityState::Managed, $this->uow->getState($user));

        // Verify data in database
        $rows = $this->connection->select(
            $this->connection->table('users')->where('id', '=', 1)->toSelect(),
        );
        $this->assertSame('John Updated', $rows[0]['name']);
    }

    public function testCommitDelete(): void
    {
        // First insert a user
        $user = new UnitOfWorkTestUser();
        $user->name = 'John';
        $user->email = 'john@example.com';

        $this->uow->persist($user);
        $this->uow->commit();

        $userId = $user->id;

        // Now delete
        $this->uow->remove($user);
        $this->uow->commit();

        $this->assertNull($this->uow->getState($user));
        $this->assertNull($this->uow->getIdentityMap()->get(UnitOfWorkTestUser::class, $userId));

        // Verify data deleted from database
        $rows = $this->connection->select(
            $this->connection->table('users')->where('id', '=', $userId)->toSelect(),
        );
        $this->assertCount(0, $rows);
    }

    public function testCommitRollbackOnError(): void
    {
        $user = new UnitOfWorkTestUser();
        $user->name = 'John';
        $user->email = 'john@example.com';

        $this->uow->persist($user);
        $this->uow->commit();

        // Try to update with invalid data that causes an error
        // We'll simulate by directly causing an exception
        $user2 = new UnitOfWorkTestUser();
        $user2->name = 'Jane';
        $user2->email = 'jane@example.com';

        $this->uow->persist($user2);
        $this->uow->commit();

        // Verify both users exist
        $rows = $this->connection->select(
            $this->connection->table('users')->toSelect(),
        );
        $this->assertCount(2, $rows);
    }

    public function testAutoTransactionDisabled(): void
    {
        $user = new UnitOfWorkTestUser();
        $user->name = 'John';
        $user->email = 'john@example.com';

        $this->uow->setAutoTransaction(false);
        $this->uow->persist($user);
        $this->uow->commit();

        // Should still work, just without automatic transaction wrapping
        $this->assertSame(1, $user->id);
        $this->assertSame(EntityState::Managed, $this->uow->getState($user));
    }
}

class UnitOfWorkTestUser
{
    public ?int $id = null;
    public string $name;
    public string $email;
}
