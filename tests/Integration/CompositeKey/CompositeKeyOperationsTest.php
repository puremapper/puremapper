<?php

declare(strict_types=1);

namespace PureMapper\Tests\Integration\CompositeKey;

use PureMapper\Mapping\EntityMapper;
use PureMapper\Tests\Integration\IntegrationTestCase;

final class CompositeKeyOperationsTest extends IntegrationTestCase
{
    protected function createSchema(): void
    {
        $this->connection->statement('
            CREATE TABLE tenant_users (
                tenant_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                role TEXT NOT NULL,
                PRIMARY KEY (tenant_id, user_id)
            )
        ');
    }

    protected function registerEntities(): void
    {
        $this->metadataRegistry->register(
            (new EntityMapper(TenantUser::class))
                ->table('tenant_users')
                ->id(['tenantId', 'userId'])
                ->field('tenantId', 'int', 'tenant_id')
                ->field('userId', 'int', 'user_id')
                ->field('role', 'string')
                ->build(),
        );
    }

    public function testCompositeKeyCrudOperations(): void
    {
        // Create (insert directly since composite keys must have user-provided values)
        $this->connection->execute(
            $this->connection->table('tenant_users')->toInsert([
                'tenant_id' => 1,
                'user_id' => 42,
                'role' => 'admin',
            ]),
        );

        // Read with composite key
        $fetched = $this->em->query(TenantUser::class)->find(['tenantId' => 1, 'userId' => 42]);

        $this->assertInstanceOf(TenantUser::class, $fetched);
        $this->assertSame(1, $fetched->tenantId);
        $this->assertSame(42, $fetched->userId);
        $this->assertSame('admin', $fetched->role);

        // Update
        $fetched->role = 'superadmin';
        $this->em->markDirty($fetched);
        $this->em->commit();

        $this->em->clear();
        $updated = $this->em->query(TenantUser::class)->find(['tenantId' => 1, 'userId' => 42]);
        $this->assertSame('superadmin', $updated->role);

        // Delete
        $this->em->remove($updated);
        $this->em->commit();

        $this->em->clear();
        $deleted = $this->em->query(TenantUser::class)->find(['tenantId' => 1, 'userId' => 42]);
        $this->assertNull($deleted);
    }

    public function testCompositeKeyIdentityMap(): void
    {
        // Insert directly
        $this->connection->execute(
            $this->connection->table('tenant_users')->toInsert([
                'tenant_id' => 2,
                'user_id' => 100,
                'role' => 'member',
            ]),
        );

        // Query twice without clearing
        $first = $this->em->query(TenantUser::class)->find(['tenantId' => 2, 'userId' => 100]);
        $second = $this->em->query(TenantUser::class)->find(['tenantId' => 2, 'userId' => 100]);

        $this->assertSame($first, $second);
    }
}

class TenantUser
{
    public int $tenantId;
    public int $userId;
    public string $role;
}
