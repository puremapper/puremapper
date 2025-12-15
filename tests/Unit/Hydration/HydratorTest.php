<?php

declare(strict_types=1);

namespace PureMapper\Tests\Unit\Hydration;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use PureMapper\Hydration\Hydrator;
use PureMapper\Mapping\EntityMapper;
use PureMapper\Mapping\MetadataRegistry;
use PureMapper\Type\TypeRegistry;

final class HydratorTest extends TestCase
{
    private Hydrator $hydrator;
    private MetadataRegistry $metadataRegistry;

    protected function setUp(): void
    {
        $this->metadataRegistry = new MetadataRegistry();
        $this->hydrator = new Hydrator(
            $this->metadataRegistry,
            new TypeRegistry(),
        );
    }

    public function testHydrateSimpleEntity(): void
    {
        $this->registerUserMetadata();

        $row = [
            'id' => 1,
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ];

        $user = $this->hydrator->hydrate(HydratorTestUser::class, $row);

        $this->assertInstanceOf(HydratorTestUser::class, $user);
        $this->assertSame(1, $user->id);
        $this->assertSame('John Doe', $user->name);
        $this->assertSame('john@example.com', $user->email);
    }

    public function testHydrateWithColumnMapping(): void
    {
        $this->metadataRegistry->register(
            (new EntityMapper(HydratorTestUser::class))
                ->table('users')
                ->id('id')
                ->field('name', 'string')
                ->field('email', 'string')
                ->field('createdAt', 'datetime', column: 'created_at')
                ->build(),
        );

        $row = [
            'id' => 1,
            'name' => 'John',
            'email' => 'john@example.com',
            'created_at' => '2024-01-15 10:30:00',
        ];

        $user = $this->hydrator->hydrate(HydratorTestUser::class, $row);

        $this->assertInstanceOf(DateTimeImmutable::class, $user->createdAt);
        $this->assertSame('2024-01-15 10:30:00', $user->createdAt->format('Y-m-d H:i:s'));
    }

    public function testHydrateWithNullValues(): void
    {
        $this->registerUserMetadata();

        $row = [
            'id' => 1,
            'name' => 'John',
            'email' => null,
        ];

        $user = $this->hydrator->hydrate(HydratorTestUser::class, $row);

        $this->assertNull($user->email);
    }

    public function testExtractEntity(): void
    {
        $this->registerUserMetadata();

        $user = new HydratorTestUser();
        $user->id = 1;
        $user->name = 'John Doe';
        $user->email = 'john@example.com';

        $data = $this->hydrator->extract($user);

        $this->assertSame([
            'id' => 1,
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ], $data);
    }

    public function testExtractWithColumnMapping(): void
    {
        $this->metadataRegistry->register(
            (new EntityMapper(HydratorTestUser::class))
                ->table('users')
                ->id('id')
                ->field('name', 'string')
                ->field('email', 'string')
                ->field('createdAt', 'datetime', column: 'created_at')
                ->build(),
        );

        $user = new HydratorTestUser();
        $user->id = 1;
        $user->name = 'John';
        $user->email = 'john@example.com';
        $user->createdAt = new DateTimeImmutable('2024-01-15 10:30:00');

        $data = $this->hydrator->extract($user);

        $this->assertSame('2024-01-15 10:30:00', $data['created_at']);
    }

    public function testGetIdentifierSingleKey(): void
    {
        $this->registerUserMetadata();

        $user = new HydratorTestUser();
        $user->id = 42;
        $user->name = 'John';
        $user->email = 'john@example.com';

        $id = $this->hydrator->getIdentifier($user);

        $this->assertSame(42, $id);
    }

    public function testGetIdentifierCompositeKey(): void
    {
        $this->metadataRegistry->register(
            (new EntityMapper(HydratorTestTenantUser::class))
                ->table('tenant_users')
                ->id(['tenantId', 'userId'])
                ->field('tenantId', 'int', column: 'tenant_id')
                ->field('userId', 'int', column: 'user_id')
                ->field('role', 'string')
                ->build(),
        );

        $tenantUser = new HydratorTestTenantUser();
        $tenantUser->tenantId = 1;
        $tenantUser->userId = 42;
        $tenantUser->role = 'admin';

        $id = $this->hydrator->getIdentifier($tenantUser);

        $this->assertSame(['tenantId' => 1, 'userId' => 42], $id);
    }

    public function testSetIdentifierSingleKey(): void
    {
        $this->registerUserMetadata();

        $user = new HydratorTestUser();
        $user->name = 'John';
        $user->email = 'john@example.com';

        $this->assertNull($user->id);

        $this->hydrator->setIdentifier($user, 123);

        $this->assertSame(123, $user->id);
    }

    public function testSetIdentifierCompositeKey(): void
    {
        $this->metadataRegistry->register(
            (new EntityMapper(HydratorTestTenantUser::class))
                ->table('tenant_users')
                ->id(['tenantId', 'userId'])
                ->field('tenantId', 'int', column: 'tenant_id')
                ->field('userId', 'int', column: 'user_id')
                ->field('role', 'string')
                ->build(),
        );

        $tenantUser = new HydratorTestTenantUser();
        $tenantUser->role = 'admin';

        $this->hydrator->setIdentifier($tenantUser, ['tenantId' => 5, 'userId' => 99]);

        $this->assertSame(5, $tenantUser->tenantId);
        $this->assertSame(99, $tenantUser->userId);
    }

    private function registerUserMetadata(): void
    {
        $this->metadataRegistry->register(
            (new EntityMapper(HydratorTestUser::class))
                ->table('users')
                ->id('id')
                ->field('name', 'string')
                ->field('email', 'string')
                ->build(),
        );
    }
}

class HydratorTestUser
{
    public ?int $id = null;
    public string $name;
    public ?string $email = null;
    public ?DateTimeImmutable $createdAt = null;
}

class HydratorTestTenantUser
{
    public int $tenantId;
    public int $userId;
    public string $role;
}
