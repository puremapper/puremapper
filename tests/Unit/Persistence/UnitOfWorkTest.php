<?php

declare(strict_types=1);

namespace PureMapper\Tests\Unit\Persistence;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PureMapper\Hydration\Hydrator;
use PureMapper\Mapping\EntityMapper;
use PureMapper\Mapping\MetadataRegistry;
use PureMapper\Persistence\EntityState;
use PureMapper\Persistence\UnitOfWork;
use PureMapper\Type\TypeRegistry;

final class UnitOfWorkTest extends TestCase
{
    private UnitOfWork $uow;
    private MockObject&ConnectionInterface $connection;
    private MetadataRegistry $metadataRegistry;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(ConnectionInterface::class);
        $this->metadataRegistry = new MetadataRegistry();
        $this->metadataRegistry->register(
            (new EntityMapper(UnitOfWorkTestUser::class))
                ->table('users')
                ->id('id')
                ->field('name', 'string')
                ->field('email', 'string')
                ->build()
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

        $this->uow->registerManaged($user, 1);
        $this->uow->markDirty($user);

        $this->assertSame(EntityState::Dirty, $this->uow->getState($user));
    }

    public function testMarkDirtyThrowsForUnmanagedEntity(): void
    {
        $user = new UnitOfWorkTestUser();
        $user->name = 'John';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Entity is not managed.');

        $this->uow->markDirty($user);
    }

    public function testRemove(): void
    {
        $user = new UnitOfWorkTestUser();
        $user->id = 1;
        $user->name = 'John';

        $this->uow->registerManaged($user, 1);
        $this->uow->remove($user);

        $this->assertSame(EntityState::Removed, $this->uow->getState($user));
    }

    public function testRemoveNewEntity(): void
    {
        $user = new UnitOfWorkTestUser();
        $user->name = 'John';

        $this->uow->persist($user);
        $this->uow->remove($user);

        $this->assertNull($this->uow->getState($user));
    }

    public function testClear(): void
    {
        $user = new UnitOfWorkTestUser();
        $user->id = 1;
        $user->name = 'John';

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

        $this->uow->registerManaged($user, 1);

        $this->assertSame($user, $this->uow->getIdentityMap()->get(UnitOfWorkTestUser::class, 1));
    }

    public function testCommitInsert(): void
    {
        $user = new UnitOfWorkTestUser();
        $user->name = 'John';
        $user->email = 'john@example.com';

        $queryBuilder = $this->createMock(Builder::class);
        $queryBuilder->expects($this->once())
            ->method('insertGetId')
            ->with($this->callback(function ($data) {
                return $data['name'] === 'John' && $data['email'] === 'john@example.com';
            }))
            ->willReturn(1);

        $this->connection->method('table')->willReturn($queryBuilder);
        $this->connection->expects($this->once())->method('beginTransaction');
        $this->connection->expects($this->once())->method('commit');

        $this->uow->persist($user);
        $this->uow->commit();

        $this->assertSame(1, $user->id);
        $this->assertSame(EntityState::Managed, $this->uow->getState($user));
    }

    public function testCommitUpdate(): void
    {
        $user = new UnitOfWorkTestUser();
        $user->id = 1;
        $user->name = 'John Updated';
        $user->email = 'john@example.com';

        $queryBuilder = $this->createMock(Builder::class);
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->expects($this->once())
            ->method('update')
            ->with($this->callback(function ($data) {
                return $data['name'] === 'John Updated' && !isset($data['id']);
            }));

        $this->connection->method('table')->willReturn($queryBuilder);
        $this->connection->expects($this->once())->method('beginTransaction');
        $this->connection->expects($this->once())->method('commit');

        $this->uow->registerManaged($user, 1);
        $this->uow->markDirty($user);
        $this->uow->commit();

        $this->assertSame(EntityState::Managed, $this->uow->getState($user));
    }

    public function testCommitDelete(): void
    {
        $user = new UnitOfWorkTestUser();
        $user->id = 1;
        $user->name = 'John';
        $user->email = 'john@example.com';

        $queryBuilder = $this->createMock(Builder::class);
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->expects($this->once())->method('delete');

        $this->connection->method('table')->willReturn($queryBuilder);
        $this->connection->expects($this->once())->method('beginTransaction');
        $this->connection->expects($this->once())->method('commit');

        $this->uow->registerManaged($user, 1);
        $this->uow->remove($user);
        $this->uow->commit();

        $this->assertNull($this->uow->getState($user));
        $this->assertNull($this->uow->getIdentityMap()->get(UnitOfWorkTestUser::class, 1));
    }

    public function testCommitRollbackOnError(): void
    {
        $user = new UnitOfWorkTestUser();
        $user->name = 'John';
        $user->email = 'john@example.com';

        $queryBuilder = $this->createMock(Builder::class);
        $queryBuilder->method('insertGetId')->willThrowException(new \RuntimeException('DB Error'));

        $this->connection->method('table')->willReturn($queryBuilder);
        $this->connection->expects($this->once())->method('beginTransaction');
        $this->connection->expects($this->once())->method('rollBack');
        $this->connection->expects($this->never())->method('commit');

        $this->uow->persist($user);

        $this->expectException(\RuntimeException::class);
        $this->uow->commit();
    }
}

class UnitOfWorkTestUser
{
    public ?int $id = null;
    public string $name;
    public string $email;
}
