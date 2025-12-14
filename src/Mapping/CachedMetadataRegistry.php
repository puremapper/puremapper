<?php

declare(strict_types=1);

namespace PureMapper\Mapping;

use Psr\SimpleCache\CacheInterface;
use PureMapper\Exception\MetadataNotFoundException;

final class CachedMetadataRegistry implements MetadataRegistryInterface
{
    private const CACHE_VERSION = 1;
    private const ALL_METADATA_KEY = '__all_metadata__';

    /**
     * Runtime cache to avoid repeated cache lookups within same request.
     *
     * @var array<class-string, EntityMetadata>
     */
    private array $runtimeCache = [];

    private bool $warmed = false;

    public function __construct(
        private readonly MetadataRegistryInterface $inner,
        private readonly CacheInterface $cache,
        private readonly string $prefix = 'puremapper_metadata_',
        private readonly ?int $ttl = null,
    ) {}

    public function register(EntityMetadata $metadata): void
    {
        $this->inner->register($metadata);

        // Invalidate cache for this entity
        $this->cache->delete($this->buildKey($metadata->entityClass));

        // Update runtime cache
        $this->runtimeCache[$metadata->entityClass] = $metadata;

        // Invalidate all-metadata cache
        $this->cache->delete($this->buildKey(self::ALL_METADATA_KEY));
    }

    /**
     * @param class-string $class
     * @throws MetadataNotFoundException
     */
    public function get(string $class): EntityMetadata
    {
        // 1. Check runtime cache first (fastest)
        if (isset($this->runtimeCache[$class])) {
            return $this->runtimeCache[$class];
        }

        // 2. Check persistent cache
        $cacheKey = $this->buildKey($class);
        $cached = $this->cache->get($cacheKey);

        if ($cached instanceof EntityMetadata) {
            $this->runtimeCache[$class] = $cached;
            return $cached;
        }

        // 3. Fall back to inner registry and cache result
        $metadata = $this->inner->get($class);
        $this->cache->set($cacheKey, $metadata, $this->ttl);
        $this->runtimeCache[$class] = $metadata;

        return $metadata;
    }

    /**
     * @param class-string $class
     */
    public function has(string $class): bool
    {
        if (isset($this->runtimeCache[$class])) {
            return true;
        }

        $cacheKey = $this->buildKey($class);
        $cached = $this->cache->get($cacheKey);

        if ($cached instanceof EntityMetadata) {
            $this->runtimeCache[$class] = $cached;
            return true;
        }

        return $this->inner->has($class);
    }

    /**
     * @return array<class-string, EntityMetadata>
     */
    public function all(): array
    {
        $cacheKey = $this->buildKey(self::ALL_METADATA_KEY);
        $cached = $this->cache->get($cacheKey);

        if (\is_array($cached)) {
            $this->runtimeCache = array_merge($this->runtimeCache, $cached);
            return $cached;
        }

        $all = $this->inner->all();
        $this->cache->set($cacheKey, $all, $this->ttl);
        $this->runtimeCache = array_merge($this->runtimeCache, $all);

        return $all;
    }

    /**
     * Warm the cache with all metadata from the inner registry.
     * Call this during application bootstrap or deployment.
     */
    public function warm(): void
    {
        if ($this->warmed) {
            return;
        }

        $allMetadata = $this->inner->all();

        foreach ($allMetadata as $class => $metadata) {
            $cacheKey = $this->buildKey($class);
            $this->cache->set($cacheKey, $metadata, $this->ttl);
            $this->runtimeCache[$class] = $metadata;
        }

        // Cache the full collection
        $this->cache->set($this->buildKey(self::ALL_METADATA_KEY), $allMetadata, $this->ttl);

        $this->warmed = true;
    }

    /**
     * Invalidate cache for a specific entity class.
     *
     * @param class-string $class
     */
    public function invalidate(string $class): void
    {
        $this->cache->delete($this->buildKey($class));
        unset($this->runtimeCache[$class]);

        // Also invalidate the all-metadata cache
        $this->cache->delete($this->buildKey(self::ALL_METADATA_KEY));
    }

    /**
     * Invalidate all cached metadata.
     */
    public function invalidateAll(): void
    {
        // Get all registered classes from inner registry to ensure complete cleanup
        $allMetadata = $this->inner->all();

        // Clear all individual entries from persistent cache
        foreach ($allMetadata as $class => $metadata) {
            $this->cache->delete($this->buildKey($class));
        }

        // Clear all-metadata cache
        $this->cache->delete($this->buildKey(self::ALL_METADATA_KEY));

        $this->runtimeCache = [];
        $this->warmed = false;
    }

    /**
     * Clear only the runtime (in-memory) cache.
     * Useful for long-running processes.
     */
    public function clearRuntimeCache(): void
    {
        $this->runtimeCache = [];
    }

    /**
     * Check if cache is warmed.
     */
    public function isWarmed(): bool
    {
        return $this->warmed;
    }

    private function buildKey(string $identifier): string
    {
        return \sprintf(
            '%sv%d_%s',
            $this->prefix,
            self::CACHE_VERSION,
            $this->normalizeClass($identifier),
        );
    }

    private function normalizeClass(string $class): string
    {
        // Replace backslashes with underscores for cache key safety
        return str_replace('\\', '_', $class);
    }
}
