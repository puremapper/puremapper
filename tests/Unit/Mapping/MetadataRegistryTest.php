<?php

declare(strict_types=1);

namespace PureMapper\Tests\Unit\Mapping;

use PHPUnit\Framework\TestCase;
use PureMapper\Exception\MetadataNotFoundException;
use PureMapper\Mapping\EntityMapper;
use PureMapper\Mapping\MetadataRegistry;

final class MetadataRegistryTest extends TestCase
{
    public function testRegisterAndGet(): void
    {
        $registry = new MetadataRegistry();
        $metadata = (new EntityMapper(RegistryTestEntity::class))
            ->table('test_entities')
            ->id('id')
            ->build();

        $registry->register($metadata);

        $this->assertSame($metadata, $registry->get(RegistryTestEntity::class));
    }

    public function testHasReturnsTrueForRegisteredClass(): void
    {
        $registry = new MetadataRegistry();
        $metadata = (new EntityMapper(RegistryTestEntity::class))
            ->table('test_entities')
            ->id('id')
            ->build();

        $registry->register($metadata);

        $this->assertTrue($registry->has(RegistryTestEntity::class));
    }

    public function testHasReturnsFalseForUnregisteredClass(): void
    {
        $registry = new MetadataRegistry();

        $this->assertFalse($registry->has(RegistryTestEntity::class));
    }

    public function testGetThrowsExceptionForUnregisteredClass(): void
    {
        $registry = new MetadataRegistry();

        $this->expectException(MetadataNotFoundException::class);
        $this->expectExceptionMessage('No metadata found for entity class');

        $registry->get(RegistryTestEntity::class);
    }

    public function testAllReturnsAllRegisteredMetadata(): void
    {
        $registry = new MetadataRegistry();

        $metadata1 = (new EntityMapper(RegistryTestEntity::class))
            ->table('test_entities')
            ->id('id')
            ->build();

        $metadata2 = (new EntityMapper(AnotherRegistryTestEntity::class))
            ->table('another_entities')
            ->id('id')
            ->build();

        $registry->register($metadata1);
        $registry->register($metadata2);

        $all = $registry->all();

        $this->assertCount(2, $all);
        $this->assertArrayHasKey(RegistryTestEntity::class, $all);
        $this->assertArrayHasKey(AnotherRegistryTestEntity::class, $all);
    }

    public function testAllReturnsEmptyArrayWhenNoMetadata(): void
    {
        $registry = new MetadataRegistry();

        $this->assertSame([], $registry->all());
    }
}

class RegistryTestEntity
{
    public ?int $id = null;
}

class AnotherRegistryTestEntity
{
    public ?int $id = null;
}
