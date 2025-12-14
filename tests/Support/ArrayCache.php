<?php

declare(strict_types=1);

namespace PureMapper\Tests\Support;

use DateInterval;
use Psr\SimpleCache\CacheInterface;

/**
 * In-memory PSR-16 cache implementation for testing.
 */
final class ArrayCache implements CacheInterface
{
    /**
     * @var array<string, mixed>
     */
    private array $data = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
    {
        $this->data[$key] = $value;
        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->data[$key]);
        return true;
    }

    public function clear(): bool
    {
        $this->data = [];
        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }
        return $result;
    }

    public function setMultiple(iterable $values, DateInterval|int|null $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }
        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }
        return true;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    /**
     * Get all cached data (for testing inspection).
     *
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }
}
