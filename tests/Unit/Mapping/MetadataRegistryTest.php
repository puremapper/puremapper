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
}

class RegistryTestEntity
{
    public ?int $id = null;
}
