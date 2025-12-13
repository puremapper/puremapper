<?php

declare(strict_types=1);

namespace PureMapper\Tests\Unit\Persistence;

use PHPUnit\Framework\TestCase;
use PureMapper\Persistence\IdentityMap;

final class IdentityMapTest extends TestCase
{
    private IdentityMap $identityMap;

    protected function setUp(): void
    {
        $this->identityMap = new IdentityMap();
    }

    public function testSetAndGet(): void
    {
        $entity = new IdentityMapTestEntity();
        $entity->id = 1;

        $this->identityMap->set(IdentityMapTestEntity::class, 1, $entity);

        $this->assertSame($entity, $this->identityMap->get(IdentityMapTestEntity::class, 1));
    }

    public function testGetReturnsNullForMissing(): void
    {
        $this->assertNull($this->identityMap->get(IdentityMapTestEntity::class, 999));
    }

    public function testHas(): void
    {
        $entity = new IdentityMapTestEntity();
        $entity->id = 1;

        $this->assertFalse($this->identityMap->has(IdentityMapTestEntity::class, 1));

        $this->identityMap->set(IdentityMapTestEntity::class, 1, $entity);

        $this->assertTrue($this->identityMap->has(IdentityMapTestEntity::class, 1));
    }

    public function testRemove(): void
    {
        $entity = new IdentityMapTestEntity();
        $entity->id = 1;

        $this->identityMap->set(IdentityMapTestEntity::class, 1, $entity);
        $this->assertTrue($this->identityMap->has(IdentityMapTestEntity::class, 1));

        $this->identityMap->remove(IdentityMapTestEntity::class, 1);
        $this->assertFalse($this->identityMap->has(IdentityMapTestEntity::class, 1));
    }

    public function testClear(): void
    {
        $entity1 = new IdentityMapTestEntity();
        $entity1->id = 1;

        $entity2 = new IdentityMapTestEntity();
        $entity2->id = 2;

        $this->identityMap->set(IdentityMapTestEntity::class, 1, $entity1);
        $this->identityMap->set(IdentityMapTestEntity::class, 2, $entity2);

        $this->identityMap->clear();

        $this->assertFalse($this->identityMap->has(IdentityMapTestEntity::class, 1));
        $this->assertFalse($this->identityMap->has(IdentityMapTestEntity::class, 2));
    }

    public function testCompositeKey(): void
    {
        $entity = new IdentityMapTestEntity();

        $this->identityMap->set(IdentityMapTestEntity::class, ['tenant_id' => 1, 'user_id' => 2], $entity);

        $this->assertTrue($this->identityMap->has(IdentityMapTestEntity::class, ['tenant_id' => 1, 'user_id' => 2]));
        $this->assertSame($entity, $this->identityMap->get(IdentityMapTestEntity::class, ['tenant_id' => 1, 'user_id' => 2]));
    }

    public function testGetAll(): void
    {
        $entity1 = new IdentityMapTestEntity();
        $entity1->id = 1;

        $entity2 = new IdentityMapTestEntity();
        $entity2->id = 2;

        $this->identityMap->set(IdentityMapTestEntity::class, 1, $entity1);
        $this->identityMap->set(IdentityMapTestEntity::class, 2, $entity2);

        $all = $this->identityMap->getAll(IdentityMapTestEntity::class);

        $this->assertCount(2, $all);
        $this->assertContains($entity1, $all);
        $this->assertContains($entity2, $all);
    }
}

class IdentityMapTestEntity
{
    public ?int $id = null;
    public string $name = '';
}
