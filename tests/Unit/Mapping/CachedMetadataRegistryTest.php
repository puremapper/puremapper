<?php

declare(strict_types=1);

namespace PureMapper\Tests\Unit\Mapping;

use PHPUnit\Framework\TestCase;
use PureMapper\Exception\MetadataNotFoundException;
use PureMapper\Mapping\CachedMetadataRegistry;
use PureMapper\Mapping\EntityMapper;
use PureMapper\Mapping\MetadataRegistry;
use PureMapper\Tests\Support\ArrayCache;

final class CachedMetadataRegistryTest extends TestCase
{
    private MetadataRegistry $innerRegistry;
    private ArrayCache $cache;
    private CachedMetadataRegistry $cachedRegistry;

    protected function setUp(): void
    {
        $this->innerRegistry = new MetadataRegistry();
        $this->cache = new ArrayCache();
        $this->cachedRegistry = new CachedMetadataRegistry(
            $this->innerRegistry,
            $this->cache,
        );
    }

    public function testGetReturnsFromInnerOnCacheMiss(): void
    {
        $metadata = (new EntityMapper(CachedTestEntity::class))
            ->table('cached_entities')
            ->id('id')
            ->build();

        $this->innerRegistry->register($metadata);

        $result = $this->cachedRegistry->get(CachedTestEntity::class);

        $this->assertSame($metadata, $result);
    }

    public function testGetPopulatesCacheOnMiss(): void
    {
        $metadata = (new EntityMapper(CachedTestEntity::class))
            ->table('cached_entities')
            ->id('id')
            ->build();

        $this->innerRegistry->register($metadata);

        // First call - cache miss
        $this->cachedRegistry->get(CachedTestEntity::class);

        // Verify cache was populated
        $cacheData = $this->cache->getData();
        $this->assertNotEmpty($cacheData);
    }

    public function testGetReturnsFromRuntimeCacheOnSecondCall(): void
    {
        $metadata = (new EntityMapper(CachedTestEntity::class))
            ->table('cached_entities')
            ->id('id')
            ->build();

        $this->innerRegistry->register($metadata);

        // First call
        $result1 = $this->cachedRegistry->get(CachedTestEntity::class);

        // Clear persistent cache to prove runtime cache is used
        $this->cache->clear();

        // Second call should use runtime cache
        $result2 = $this->cachedRegistry->get(CachedTestEntity::class);

        $this->assertSame($result1, $result2);
    }

    public function testGetReturnsFromPersistentCacheOnNewInstance(): void
    {
        $metadata = (new EntityMapper(CachedTestEntity::class))
            ->table('cached_entities')
            ->id('id')
            ->build();

        $this->innerRegistry->register($metadata);

        // First instance populates cache
        $this->cachedRegistry->get(CachedTestEntity::class);

        // Create new instance with same cache but empty inner registry
        $emptyInner = new MetadataRegistry();
        $newCachedRegistry = new CachedMetadataRegistry($emptyInner, $this->cache);

        // Should retrieve from persistent cache
        $result = $newCachedRegistry->get(CachedTestEntity::class);

        $this->assertEquals($metadata->entityClass, $result->entityClass);
        $this->assertEquals($metadata->table, $result->table);
    }

    public function testHasReturnsTrueWhenInRuntimeCache(): void
    {
        $metadata = (new EntityMapper(CachedTestEntity::class))
            ->table('cached_entities')
            ->id('id')
            ->build();

        $this->innerRegistry->register($metadata);

        // Populate runtime cache
        $this->cachedRegistry->get(CachedTestEntity::class);

        // Clear persistent cache
        $this->cache->clear();

        $this->assertTrue($this->cachedRegistry->has(CachedTestEntity::class));
    }

    public function testHasReturnsTrueWhenInPersistentCache(): void
    {
        $metadata = (new EntityMapper(CachedTestEntity::class))
            ->table('cached_entities')
            ->id('id')
            ->build();

        $this->innerRegistry->register($metadata);

        // Populate cache
        $this->cachedRegistry->get(CachedTestEntity::class);

        // Create new instance (empty runtime cache)
        $newCachedRegistry = new CachedMetadataRegistry(
            new MetadataRegistry(),
            $this->cache,
        );

        $this->assertTrue($newCachedRegistry->has(CachedTestEntity::class));
    }

    public function testHasFallsBackToInnerRegistry(): void
    {
        $metadata = (new EntityMapper(CachedTestEntity::class))
            ->table('cached_entities')
            ->id('id')
            ->build();

        $this->innerRegistry->register($metadata);

        // Don't populate cache, check inner registry
        $this->assertTrue($this->cachedRegistry->has(CachedTestEntity::class));
    }

    public function testHasReturnsFalseForUnregisteredClass(): void
    {
        $this->assertFalse($this->cachedRegistry->has(CachedTestEntity::class));
    }

    public function testRegisterInvalidatesCache(): void
    {
        $metadata1 = (new EntityMapper(CachedTestEntity::class))
            ->table('cached_entities')
            ->id('id')
            ->build();

        $this->innerRegistry->register($metadata1);
        $this->cachedRegistry->get(CachedTestEntity::class);

        // Register new metadata through cached registry
        $metadata2 = (new EntityMapper(CachedTestEntity::class))
            ->table('updated_entities')
            ->id('id')
            ->build();

        $this->cachedRegistry->register($metadata2);

        $result = $this->cachedRegistry->get(CachedTestEntity::class);

        $this->assertEquals('updated_entities', $result->table);
    }

    public function testWarmPopulatesCache(): void
    {
        $metadata1 = (new EntityMapper(CachedTestEntity::class))
            ->table('cached_entities')
            ->id('id')
            ->build();

        $metadata2 = (new EntityMapper(AnotherCachedEntity::class))
            ->table('another_entities')
            ->id('id')
            ->build();

        $this->innerRegistry->register($metadata1);
        $this->innerRegistry->register($metadata2);

        $this->cachedRegistry->warm();

        $this->assertTrue($this->cachedRegistry->isWarmed());

        // Verify both are cached
        $cacheData = $this->cache->getData();
        $this->assertGreaterThanOrEqual(2, count($cacheData));
    }

    public function testWarmIsIdempotent(): void
    {
        $metadata = (new EntityMapper(CachedTestEntity::class))
            ->table('cached_entities')
            ->id('id')
            ->build();

        $this->innerRegistry->register($metadata);

        $this->cachedRegistry->warm();
        $this->cachedRegistry->warm();

        $this->assertTrue($this->cachedRegistry->isWarmed());
    }

    public function testInvalidateRemovesSingleEntry(): void
    {
        $metadata = (new EntityMapper(CachedTestEntity::class))
            ->table('cached_entities')
            ->id('id')
            ->build();

        $this->innerRegistry->register($metadata);
        $this->cachedRegistry->get(CachedTestEntity::class);

        $this->cachedRegistry->invalidate(CachedTestEntity::class);

        // Create new instance to verify persistent cache was cleared
        $newCachedRegistry = new CachedMetadataRegistry(
            new MetadataRegistry(),
            $this->cache,
        );

        $this->assertFalse($newCachedRegistry->has(CachedTestEntity::class));
    }

    public function testInvalidateAllClearsEverything(): void
    {
        $metadata1 = (new EntityMapper(CachedTestEntity::class))
            ->table('cached_entities')
            ->id('id')
            ->build();

        $metadata2 = (new EntityMapper(AnotherCachedEntity::class))
            ->table('another_entities')
            ->id('id')
            ->build();

        $this->innerRegistry->register($metadata1);
        $this->innerRegistry->register($metadata2);

        $this->cachedRegistry->warm();
        $this->cachedRegistry->invalidateAll();

        $this->assertFalse($this->cachedRegistry->isWarmed());

        // Verify runtime cache is cleared
        $this->cachedRegistry->clearRuntimeCache();

        // Create new instance
        $newCachedRegistry = new CachedMetadataRegistry(
            new MetadataRegistry(),
            $this->cache,
        );

        $this->assertFalse($newCachedRegistry->has(CachedTestEntity::class));
        $this->assertFalse($newCachedRegistry->has(AnotherCachedEntity::class));
    }

    public function testClearRuntimeCacheOnlyClearsMemory(): void
    {
        $metadata = (new EntityMapper(CachedTestEntity::class))
            ->table('cached_entities')
            ->id('id')
            ->build();

        $this->innerRegistry->register($metadata);
        $this->cachedRegistry->get(CachedTestEntity::class);

        $this->cachedRegistry->clearRuntimeCache();

        // Persistent cache should still have the entry
        $this->assertTrue($this->cache->has($this->buildExpectedKey(CachedTestEntity::class)));
    }

    public function testAllReturnsAllMetadata(): void
    {
        $metadata1 = (new EntityMapper(CachedTestEntity::class))
            ->table('cached_entities')
            ->id('id')
            ->build();

        $metadata2 = (new EntityMapper(AnotherCachedEntity::class))
            ->table('another_entities')
            ->id('id')
            ->build();

        $this->innerRegistry->register($metadata1);
        $this->innerRegistry->register($metadata2);

        $all = $this->cachedRegistry->all();

        $this->assertCount(2, $all);
        $this->assertArrayHasKey(CachedTestEntity::class, $all);
        $this->assertArrayHasKey(AnotherCachedEntity::class, $all);
    }

    public function testCustomPrefixIsolatesCacheEntries(): void
    {
        $metadata = (new EntityMapper(CachedTestEntity::class))
            ->table('cached_entities')
            ->id('id')
            ->build();

        $this->innerRegistry->register($metadata);

        $cachedRegistry1 = new CachedMetadataRegistry(
            $this->innerRegistry,
            $this->cache,
            'app1_',
        );

        $cachedRegistry2 = new CachedMetadataRegistry(
            new MetadataRegistry(),
            $this->cache,
            'app2_',
        );

        $cachedRegistry1->get(CachedTestEntity::class);

        // app2 registry should not find it
        $this->assertFalse($cachedRegistry2->has(CachedTestEntity::class));
    }

    public function testGetThrowsExceptionForUnregisteredClass(): void
    {
        $this->expectException(MetadataNotFoundException::class);

        $this->cachedRegistry->get(CachedTestEntity::class);
    }

    private function buildExpectedKey(string $class): string
    {
        $normalized = str_replace('\\', '_', $class);
        return "puremapper_metadata_v1_{$normalized}";
    }
}

class CachedTestEntity
{
    public ?int $id = null;
}

class AnotherCachedEntity
{
    public ?int $id = null;
}
