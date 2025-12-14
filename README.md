# PureMapper

**PureMapper** is a lightweight **Data Mapper** and **Unit of Work** library for **pure PHP entities**.

It is designed for developers who want:

* Pure PHP domain models (no annotations, no attributes)
* No Active Record, no magic methods
* Clear separation between **domain** and **infrastructure**
* A small, understandable alternative to heavy ORMs
* Battle-tested SQL execution via **Laravel Query Builder**

> *Doctrine ideas, without Doctrine weight.*

---

## Quick Start

```php
// 1. Define a pure entity
final class User
{
    public ?int $id = null;
    public string $name;
    public string $email;
    public DateTimeImmutable $createdAt;
}

// 2. Define mapping externally
$registry = new MetadataRegistry();
$registry->register(
    (new EntityMapper(User::class))
        ->table('users')
        ->id('id')
        ->field('name', 'string')
        ->field('email', 'string')
        ->field('createdAt', 'datetime', column: 'created_at')
        ->build()
);

// 3. Query entities with relations
$user = $em->query(User::class)
    ->with('posts')
    ->find(1);

// Or use repositories for domain-specific queries
class UserRepository implements RepositoryInterface
{
    public function __construct(
        private EntityManager $em,
    ) {}

    public function findActiveWithPosts(): array
    {
        return $this->em->query(User::class)
            ->with('posts')
            ->where('status', '=', 'active')
            ->get();
    }
}

// 4. Persist entities
$user = new User();
$user->name = 'John';
$user->email = 'john@example.com';
$user->createdAt = new DateTimeImmutable();

$uow->persist($user);
$uow->commit(); // INSERT executed, $user->id populated
```

---

## Philosophy

PureMapper follows the **Data Mapper** pattern:

* Entities are **plain PHP objects** with no persistence awareness
* Mapping is defined **externally** using a fluent DSL
* Persistence logic lives outside your domain
* Infrastructure can be replaced without touching entities

```text
Domain (Pure PHP Entities)
         |
    RepositoryInterface
         |
    EntityManager
         |
  EntityQuery + UnitOfWork + Hydrator
         |
   Query Builder (Illuminate Database)
         |
      Database
```

---

## Requirements

* PHP **8.1+**
* `illuminate/database` ^10 || ^11 || ^12

> PureMapper does **NOT** depend on Laravel as a framework.
> Only the database component is used as a SQL execution layer.

---

## Installation

```bash
composer require puremapper/puremapper
```

---

## Defining Entities

Entities are **pure PHP classes** with no persistence logic, no annotations, and no base class.

```php
final class User
{
    public ?int $id = null;
    public string $name;
    public string $email;

    /** @var Post[] */
    public array $posts = [];

    public ?Profile $profile = null;
}

final class Post
{
    public ?int $id = null;
    public string $title;
    public string $content;
    public DateTimeImmutable $publishedAt;
}
```

Hydration assigns values directly to **public properties**. No setters required.

---

## Mapping with Fluent DSL

Mappings are defined externally using a fluent builder API.

```php
use PureMapper\Mapping\EntityMapper;

$mapper = (new EntityMapper(User::class))
    ->table('users')
    ->id('id')                              // Single primary key
    ->field('name', 'string')
    ->field('email', 'string')
    ->field('createdAt', 'datetime', column: 'created_at')
    ->hasMany('posts', Post::class, foreignKey: 'user_id')
    ->hasOne('profile', Profile::class, foreignKey: 'user_id');

$metadata = $mapper->build();
```

### Composite Primary Keys

```php
$mapper = (new EntityMapper(TenantUser::class))
    ->table('tenant_users')
    ->id(['tenant_id', 'user_id'])  // Composite key
    ->field('role', 'string');
```

### Column Name Mapping

```php
->field('createdAt', 'datetime', column: 'created_at')
->field('isActive', 'bool', column: 'is_active')
```

---

## Type Conversion

PureMapper includes built-in type converters and supports custom converters.

### Built-in Types

| Type       | PHP Type                  | Database Type    |
|------------|---------------------------|------------------|
| `string`   | `string`                  | VARCHAR/TEXT     |
| `int`      | `int`                     | INTEGER          |
| `float`    | `float`                   | DECIMAL/FLOAT    |
| `bool`     | `bool`                    | BOOLEAN/TINYINT  |
| `datetime` | `DateTimeImmutable`       | DATETIME         |
| `date`     | `DateTimeImmutable`       | DATE             |
| `json`     | `array`                   | JSON/TEXT        |
| `enum`     | `BackedEnum`              | VARCHAR/INTEGER  |

### Custom Type Converters

```php
use PureMapper\Type\TypeConverter;

final class MoneyConverter implements TypeConverter
{
    public function toPHP(mixed $value): Money
    {
        return Money::fromCents((int) $value);
    }

    public function toDatabase(mixed $value): int
    {
        return $value->cents();
    }
}

// Register custom type
$typeRegistry->register('money', new MoneyConverter());

// Use in mapping
->field('price', 'money')
```

---

## Relations

### Supported Relation Types

| Relation     | Example                                                   |
|--------------|-----------------------------------------------------------|
| `hasOne`     | `->hasOne('profile', Profile::class, 'user_id')`          |
| `hasMany`    | `->hasMany('posts', Post::class, 'user_id')`              |
| `belongsTo`  | `->belongsTo('author', User::class, 'author_id')`         |
| `manyToMany` | `->manyToMany('tags', Tag::class, 'post_tags', 'post_id', 'tag_id')` |

### Loading Strategy

Relations use **eager loading only** - no lazy loading or N+1 surprises. Use the Query Builder's `with()` method to load relations.

---

## Query Builder

PureMapper provides a fluent Query Builder for querying entities with eager-loaded relations.

### Basic Queries

```php
use PureMapper\Query\EntityQuery;

// Get all users
$users = $em->query(User::class)->get();

// Find by primary key
$user = $em->query(User::class)->find(1);

// Find with conditions
$users = $em->query(User::class)
    ->where('status', '=', 'active')
    ->where('created_at', '>', '2024-01-01')
    ->orderBy('name', 'asc')
    ->limit(10)
    ->get();

// Get first matching result
$user = $em->query(User::class)
    ->where('email', '=', 'john@example.com')
    ->first();
```

### Eager Loading Relations

Use the `with()` method to eager load relations in a single query batch:

```php
// Load user with posts
$user = $em->query(User::class)
    ->with('posts')
    ->find(1);

// Load multiple relations
$users = $em->query(User::class)
    ->with('posts')
    ->with('profile')
    ->where('status', '=', 'active')
    ->get();

// Multiple relations in one call
$users = $em->query(User::class)
    ->with('posts', 'profile', 'roles')
    ->get();
```

### How Eager Loading Works

Relations are loaded using **separate queries** (not JOINs) to avoid cartesian product issues:

```php
$users = $em->query(User::class)
    ->with('posts')
    ->where('status', '=', 'active')
    ->get();

// Executes:
// 1. SELECT * FROM users WHERE status = 'active'
// 2. SELECT * FROM posts WHERE user_id IN (1, 2, 3, ...)
```

This approach:
- Avoids duplicate parent rows from JOINs
- Maintains predictable memory usage
- Works efficiently with the identity map

> **Note:** PureMapper does not support constrained or nested eager loading.
> Complex relation queries should be expressed explicitly using repositories and the Query Builder.

### Query Builder in Repositories

You can use the Query Builder inside repositories for complex queries:

```php
final class UserRepository implements RepositoryInterface
{
    public function __construct(
        private EntityManager $em,
    ) {}

    public function findActiveWithPosts(): array
    {
        return $this->em->query(User::class)
            ->with('posts')
            ->where('status', '=', 'active')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function findByEmailWithProfile(string $email): ?User
    {
        return $this->em->query(User::class)
            ->with('profile')
            ->where('email', '=', $email)
            ->first();
    }
}
```

---

## Unit of Work

The Unit of Work tracks entity state and coordinates persistence.

```php
// Create new entities
$user = new User();
$user->name = 'John';
$uow->persist($user);

// Modify existing entities (explicit dirty marking)
$user->email = 'new@example.com';
$uow->markDirty($user);

// Remove entities
$uow->remove($user);

// Commit all changes in a transaction
$uow->commit();
```

### Change Tracking

PureMapper uses **explicit change tracking**. You must call `markDirty()` on modified entities:

```php
$user = $repository->find(1);
$user->name = 'Updated Name';
$uow->markDirty($user);  // Required to trigger UPDATE
$uow->commit();
```

This design is intentional - no hidden magic, no unexpected queries.

### Cascade Persist

New related entities are automatically persisted when the parent is persisted:

```php
$user = new User();
$user->name = 'John';

$post = new Post();
$post->title = 'Hello World';
$user->posts[] = $post;

$uow->persist($user);
$uow->commit(); // Both User and Post are inserted
```

> Note: Cascade remove is not supported. Remove entities explicitly.

### Transaction Control

By default, `commit()` wraps all operations in a transaction. For manual control:

```php
// Auto transaction (default)
$uow->commit();

// Manual transaction control
$uow->setAutoTransaction(false);
$conn->beginTransaction();
try {
    $uow->commit();
    $conn->commit();
} catch (Exception $e) {
    $conn->rollBack();
    throw $e;
}
```

---

## Metadata Caching

For production environments, PureMapper supports PSR-16 metadata caching to avoid rebuilding entity mappings on every request.

### Development vs Production

| Environment | Registry | Reason |
|-------------|----------|--------|
| Development | `MetadataRegistry` | No cache, changes apply immediately |
| Production | `CachedMetadataRegistry` | Performance, metadata rarely changes |

### Basic Setup

```php
use PureMapper\Mapping\MetadataRegistry;
use PureMapper\Mapping\CachedMetadataRegistry;
use Psr\SimpleCache\CacheInterface;

// Development - no caching
$registry = new MetadataRegistry();

// Production - with PSR-16 cache
/** @var CacheInterface $cache */
$cachedRegistry = new CachedMetadataRegistry(
    $registry,
    $cache,
    prefix: 'puremapper_metadata_',  // Optional: custom prefix
    ttl: 3600,                        // Optional: TTL in seconds
);
```

### Cache Warming

For best performance, warm the cache during deployment:

```php
// Register all entity mappings
$registry->register($userMetadata);
$registry->register($postMetadata);

// Warm cache (stores all metadata in persistent cache)
$cachedRegistry->warm();
```

### Cache Invalidation

Invalidate cache when entity mappings change (typically during deployment):

```php
// Invalidate single entity
$cachedRegistry->invalidate(User::class);

// Invalidate all cached metadata
$cachedRegistry->invalidateAll();

// Clear only runtime (in-memory) cache - useful for long-running processes
$cachedRegistry->clearRuntimeCache();
```

### How It Works

`CachedMetadataRegistry` uses two-tier caching:

1. **Runtime cache** (array) - Zero overhead lookups within same request
2. **Persistent cache** (PSR-16) - Cross-request caching (Redis, Memcached, file, etc.)

Cache keys are versioned (`puremapper_metadata_v1_...`) to allow automatic invalidation when the internal structure changes.

### Multi-Application Support

Use custom prefixes to isolate cache entries in shared cache environments:

```php
// App 1
$registry1 = new CachedMetadataRegistry($inner, $cache, prefix: 'app1_metadata_');

// App 2
$registry2 = new CachedMetadataRegistry($inner, $cache, prefix: 'app2_metadata_');
```

---

## Identity Map

The identity map is scoped to a single UnitOfWork instance.
It is cleared after commit(), rollback(), or clear().

The Unit of Work maintains an **identity map** to ensure:

* Same database row always returns the same object instance
* Circular references are handled correctly
* Entity identity is preserved across operations

```php
$user1 = $repository->find(1);
$user2 = $repository->find(1);

assert($user1 === $user2); // Same instance
```

---

## Repository Interface (OPTIONAL)

PureMapper provides a repository interface. Implementation is yours:

```php
use PureMapper\Repository\RepositoryInterface;

/**
 * @template T of object
 */
interface RepositoryInterface
{
    /** @return T|null */
    public function find(int|string|array $id): ?object;

    /** @return T[] */
    public function findAll(): array;

    /** @return T[] */
    public function findBy(array $criteria): array;
}
```

### Example Implementation

```php
final class UserRepository implements RepositoryInterface
{
    public function __construct(
        private EntityManager $em,
    ) {}

    public function find(int|string|array $id): ?User
    {
        return $this->em->query(User::class)->find($id);
    }

    public function findAll(): array
    {
        return $this->em->query(User::class)->get();
    }

    public function findBy(array $criteria): array
    {
        $query = $this->em->query(User::class);

        foreach ($criteria as $field => $value) {
            $query->where($field, '=', $value);
        }

        return $query->get();
    }

    public function findByEmail(string $email): ?User
    {
        return $this->em->query(User::class)
            ->where('email', '=', $email)
            ->first();
    }

    public function findActiveWithPosts(): array
    {
        return $this->em->query(User::class)
            ->with('posts')
            ->where('status', '=', 'active')
            ->orderBy('created_at', 'desc')
            ->get();
    }
}
```

---

## Why PureMapper?

| Feature                   | PureMapper | Doctrine | Eloquent |
|---------------------------|------------|----------|----------|
| Pure entities             | Yes        | Partial  | No       |
| No annotations/attributes | Yes        | No       | No       |
| Lightweight               | Yes        | No       | No       |
| Explicit mapping          | Yes        | Partial  | No       |
| Framework agnostic domain | Yes        | Yes      | No       |
| Explicit change tracking  | Yes        | No       | No       |
| No proxy generation       | Yes        | No       | Yes      |
| Fluent Query Builder      | Yes(thin)  | Partial  | Yes      |
| Eager loading with `with()`| Yes       | Yes      | Yes      |

---

## When NOT to Use PureMapper

PureMapper is intentionally minimal. Do not use it if you need:

* Automatic graph synchronization (aggregate boundaries must be explicit)
* Schema migrations (use Doctrine Migrations or Laravel Migrations separately)
* Automatic dirty checking (PureMapper requires explicit `markDirty()`)
* Lazy loading (PureMapper uses eager loading only)
* Complex inheritance mapping

---

## Roadmap

### Completed

* [x] Metadata Caching (PSR-16 support with `CachedMetadataRegistry`)

### Planned

* [ ] Event dispatching (prePersist, postPersist, preUpdate, postUpdate, preRemove, postRemove, postLoad)
* [ ] Embedded/Value Objects (e.g., Money, Address mapping to multiple columns)
* [ ] Soft Deletes (automatic `deleted_at` filtering)

---

## License

MIT
