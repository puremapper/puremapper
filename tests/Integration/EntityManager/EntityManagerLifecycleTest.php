<?php

declare(strict_types=1);

namespace PureMapper\Tests\Integration\EntityManager;

use PureMapper\Mapping\EntityMapper;
use PureMapper\Tests\Integration\IntegrationTestCase;

final class EntityManagerLifecycleTest extends IntegrationTestCase
{
    protected function createSchema(): void
    {
        $this->connection->statement('
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT
            )
        ');
    }

    protected function registerEntities(): void
    {
        $this->metadataRegistry->register(
            (new EntityMapper(LifecycleTestUser::class))
                ->table('users')
                ->id('id')
                ->field('name', 'string')
                ->field('email', 'string')
                ->build()
        );
    }

    public function testFullCrudLifecycle(): void
    {
        // Create
        $user = new LifecycleTestUser();
        $user->name = 'John Doe';
        $user->email = 'john@example.com';

        $this->em->persist($user);
        $this->em->commit();

        $this->assertNotNull($user->id);
        $insertedId = $user->id;

        // Read
        $this->em->clear();
        $fetchedUser = $this->em->query(LifecycleTestUser::class)->find($insertedId);

        $this->assertInstanceOf(LifecycleTestUser::class, $fetchedUser);
        $this->assertSame($insertedId, $fetchedUser->id);
        $this->assertSame('John Doe', $fetchedUser->name);
        $this->assertSame('john@example.com', $fetchedUser->email);

        // Update
        $fetchedUser->name = 'John Updated';
        $this->em->markDirty($fetchedUser);
        $this->em->commit();

        $this->em->clear();
        $updatedUser = $this->em->query(LifecycleTestUser::class)->find($insertedId);
        $this->assertSame('John Updated', $updatedUser->name);

        // Delete
        $this->em->remove($updatedUser);
        $this->em->commit();

        $this->em->clear();
        $deletedUser = $this->em->query(LifecycleTestUser::class)->find($insertedId);
        $this->assertNull($deletedUser);
    }

    public function testTransactionRollbackOnError(): void
    {
        $user = new LifecycleTestUser();
        $user->name = 'Before Error';
        $user->email = 'before@example.com';

        $this->em->persist($user);
        $this->em->commit();

        $initialCount = $this->connection->table('users')->count();

        // Force an error by inserting a user with null name (NOT NULL constraint)
        $badUser = new LifecycleTestUser();
        $badUser->email = 'bad@example.com';

        $this->em->persist($badUser);

        try {
            $this->em->commit();
            $this->fail('Expected exception was not thrown');
        } catch (\Throwable) {
            // Expected
        }

        // Verify count unchanged (rollback worked)
        $this->assertSame($initialCount, $this->connection->table('users')->count());
    }

    public function testIdentityMapReturnsExistingInstance(): void
    {
        $user = new LifecycleTestUser();
        $user->name = 'Identity Test';
        $user->email = 'identity@example.com';

        $this->em->persist($user);
        $this->em->commit();

        $id = $user->id;

        // Query twice without clearing
        $first = $this->em->query(LifecycleTestUser::class)->find($id);
        $second = $this->em->query(LifecycleTestUser::class)->find($id);

        $this->assertSame($first, $second);
    }
}

class LifecycleTestUser
{
    public ?int $id = null;
    public string $name;
    public ?string $email = null;
}
