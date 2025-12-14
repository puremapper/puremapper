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

## Table of Contents

- [Quick Start](#quick-start)
- [Philosophy](#philosophy)
- [Requirements](#requirements)
- [Installation](#installation)
- [Defining Entities](#defining-entities)
- [Mapping with Fluent DSL](#mapping-with-fluent-dsl)
- [Type Conversion](#type-conversion)
- [Relations](#relations)
- [Query Builder](#query-builder)
- [Unit of Work](#unit-of-work)
- [Metadata Caching](#metadata-caching)
- [Identity Map](#identity-map)
- [Repository Interface](#repository-interface-optional)
- [Advanced Usage](#advanced-usage)
- [Why PureMapper?](#why-puremapper)
- [When NOT to Use PureMapper](#when-not-to-use-puremapper)
- [Roadmap](#roadmap)
- [License](#license)

---

## Quick Start

```php
// 1. Set up database connection (illuminate/database)
use Illuminate\Database\Capsule\Manager as Capsule;

$capsule = new Capsule();
$capsule->addConnection([
    'driver'    => 'mysql',
    'host'      => 'localhost',
    'database'  => 'myapp',
    'username'  => 'root',
    'password'  => '',
    'charset'   => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
]);
$connection = $capsule->getConnection();

// 2. Define a pure entity
final class User
{
    public ?int $id = null;
    public string $name;
    public string $email;
    public DateTimeImmutable $createdAt;
}

// 3. Set up PureMapper
use PureMapper\EntityManager;
use PureMapper\Mapping\EntityMapper;
use PureMapper\Mapping\MetadataRegistry;
use PureMapper\Type\TypeRegistry;

$typeRegistry = new TypeRegistry();
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

$em = new EntityManager($connection, $registry, $typeRegistry);

// 4. Query entities with relations
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

// 5. Persist entities
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

## Advanced Usage

### Complex Query Composition

Build sophisticated queries by chaining multiple conditions:

```php
// Multiple conditions with different operators
$users = $em->query(User::class)
    ->where('status', '=', 'active')
    ->where('age', '>=', 18)
    ->where('role', '!=', 'guest')
    ->where('email', 'LIKE', '%@company.com')
    ->orderBy('created_at', 'desc')
    ->orderBy('name', 'asc')
    ->limit(20)
    ->offset(40)
    ->get();

// Composite primary key lookup
$tenantUser = $em->query(TenantUser::class)
    ->find(['tenant_id' => 1, 'user_id' => 42]);

// Combine filtering with eager loading
$orders = $em->query(Order::class)
    ->with('items', 'customer')
    ->where('status', '=', 'pending')
    ->where('total', '>', 100)
    ->orderBy('created_at', 'desc')
    ->limit(50)
    ->get();
```

### Hydration Flow

Hydration is the process of converting database rows into PHP objects. Here's how it works:

```
Database Row (array)
       |
       v
+------------------+
|    Hydrator      |
+------------------+
       |
       | 1. Create empty instance (no constructor)
       | 2. For each mapped field:
       |    - Get value from row
       |    - Apply TypeConverter if registered
       |    - Set property via reflection
       v
PHP Entity (object)
```

**Example flow:**

```php
// Database returns:
['id' => 1, 'name' => 'John', 'created_at' => '2024-12-14 10:30:00', 'settings' => '{"theme":"dark"}']

// After hydration:
$user->id = 1;                                              // int (no conversion)
$user->name = 'John';                                       // string (no conversion)
$user->createdAt = DateTimeImmutable('2024-12-14 10:30:00'); // DateTimeConverter applied
$user->settings = ['theme' => 'dark'];                      // JsonConverter applied
```

**Extraction** (reverse process for INSERT/UPDATE):

```php
// PHP Entity:
$user->id = 1;
$user->createdAt = new DateTimeImmutable('2024-12-14');
$user->settings = ['theme' => 'dark'];

// After extraction (ready for database):
['id' => 1, 'created_at' => '2024-12-14 00:00:00', 'settings' => '{"theme":"dark"}']
```

### Custom TypeConverter

Create converters for domain-specific types:

```php
use PureMapper\Type\TypeConverter;

// Value Object for money
final class Money
{
    public function __construct(
        public readonly int $cents,
        public readonly string $currency = 'USD',
    ) {}

    public static function fromCents(int $cents): self
    {
        return new self($cents);
    }
}

// Converter implementation
final class MoneyConverter implements TypeConverter
{
    public function toPHP(mixed $value): Money
    {
        return Money::fromCents((int) $value);
    }

    public function toDatabase(mixed $value): int
    {
        return $value->cents;
    }
}

// UUID converter example
final class UuidConverter implements TypeConverter
{
    public function toPHP(mixed $value): Uuid
    {
        return Uuid::fromString((string) $value);
    }

    public function toDatabase(mixed $value): string
    {
        return $value->toString();
    }
}

// Register and use
$typeRegistry->register('money', new MoneyConverter());
$typeRegistry->register('uuid', new UuidConverter());

$mapper = (new EntityMapper(Product::class))
    ->table('products')
    ->id('id', 'uuid')           // UUID primary key
    ->field('name', 'string')
    ->field('price', 'money')    // Stored as cents in DB
    ->build();
```

### Raw Queries with Manual Hydration

For complex queries (JOINs, subqueries, aggregations), use raw SQL with manual hydration:

```php
final class OrderRepository implements RepositoryInterface
{
    public function __construct(
        private EntityManager $em,
    ) {}

    /**
     * Complex JOIN query - get orders with customer data in single query.
     */
    public function findWithCustomerDetails(int $orderId): ?array
    {
        $sql = <<<'SQL'
            SELECT
                o.id, o.status, o.total, o.created_at,
                c.id as customer_id, c.name as customer_name, c.email as customer_email
            FROM orders o
            INNER JOIN customers c ON c.id = o.customer_id
            WHERE o.id = ?
        SQL;

        $row = $this->em->getConnection()->selectOne($sql, [$orderId]);

        if ($row === null) {
            return null;
        }

        return [
            'order' => $this->em->getHydrator()->hydrate(Order::class, (array) $row),
            'customer' => $this->em->getHydrator()->hydrate(Customer::class, [
                'id' => $row->customer_id,
                'name' => $row->customer_name,
                'email' => $row->customer_email,
            ]),
        ];
    }

    /**
     * Subquery example - find customers with order count.
     */
    public function findTopCustomers(int $minOrders = 5): array
    {
        $sql = <<<'SQL'
            SELECT
                c.*,
                (SELECT COUNT(*) FROM orders WHERE customer_id = c.id) as order_count,
                (SELECT SUM(total) FROM orders WHERE customer_id = c.id) as total_spent
            FROM customers c
            HAVING order_count >= ?
            ORDER BY total_spent DESC
            LIMIT 100
        SQL;

        $rows = $this->em->getConnection()->select($sql, [$minOrders]);
        $hydrator = $this->em->getHydrator();

        return array_map(function ($row) use ($hydrator) {
            $customer = $hydrator->hydrate(Customer::class, (array) $row);
            // Attach computed fields
            $customer->orderCount = (int) $row->order_count;
            $customer->totalSpent = (int) $row->total_spent;
            return $customer;
        }, $rows);
    }

    /**
     * Aggregation query - monthly sales report.
     */
    public function getMonthlySalesReport(int $year): array
    {
        $sql = <<<'SQL'
            SELECT
                MONTH(created_at) as month,
                COUNT(*) as order_count,
                SUM(total) as revenue,
                AVG(total) as avg_order_value
            FROM orders
            WHERE YEAR(created_at) = ? AND status = 'completed'
            GROUP BY MONTH(created_at)
            ORDER BY month
        SQL;

        return $this->em->getConnection()->select($sql, [$year]);
    }

    /**
     * Complex WHERE with OR conditions using Query Builder.
     */
    public function searchOrders(string $keyword): array
    {
        $rows = $this->em->getConnection()
            ->table('orders')
            ->where(function ($query) use ($keyword) {
                $query->where('id', '=', $keyword)
                    ->orWhere('reference', 'LIKE', "%{$keyword}%")
                    ->orWhere('notes', 'LIKE', "%{$keyword}%");
            })
            ->where('status', '!=', 'cancelled')
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();

        return array_map(
            fn ($row) => $this->em->getHydrator()->hydrate(Order::class, (array) $row),
            $rows->all()
        );
    }

    /**
     * Batch hydration with relations loaded separately.
     */
    public function findRecentWithItems(int $days = 7): array
    {
        // 1. Get orders with raw query
        $sql = <<<'SQL'
            SELECT o.*
            FROM orders o
            WHERE o.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
              AND o.status IN ('pending', 'processing')
            ORDER BY o.created_at DESC
        SQL;

        $rows = $this->em->getConnection()->select($sql, [$days]);
        $hydrator = $this->em->getHydrator();

        $orders = array_map(
            fn ($row) => $hydrator->hydrate(Order::class, (array) $row),
            $rows
        );

        if (empty($orders)) {
            return [];
        }

        // 2. Batch load items (avoid N+1)
        $orderIds = array_map(fn ($o) => $o->id, $orders);
        $itemRows = $this->em->getConnection()
            ->table('order_items')
            ->whereIn('order_id', $orderIds)
            ->get();

        // 3. Group items by order_id
        $itemsByOrder = [];
        foreach ($itemRows as $row) {
            $itemsByOrder[$row->order_id][] = $hydrator->hydrate(OrderItem::class, (array) $row);
        }

        // 4. Assign items to orders
        foreach ($orders as $order) {
            $order->items = $itemsByOrder[$order->id] ?? [];
        }

        return $orders;
    }

    // Standard interface methods
    public function find(int|string|array $id): ?Order
    {
        return $this->em->query(Order::class)->find($id);
    }

    public function findAll(): array
    {
        return $this->em->query(Order::class)->get();
    }

    public function findBy(array $criteria): array
    {
        $query = $this->em->query(Order::class);
        foreach ($criteria as $field => $value) {
            $query->where($field, '=', $value);
        }
        return $query->get();
    }
}
```

**Key methods for raw queries:**

| Method | Returns | Use Case |
|--------|---------|----------|
| `$em->getConnection()` | `ConnectionInterface` | Access Illuminate Query Builder |
| `$em->getHydrator()` | `Hydrator` | Convert rows to entities |
| `$connection->select($sql, $bindings)` | `array` | Raw SELECT query |
| `$connection->selectOne($sql, $bindings)` | `object\|null` | Single row query |
| `$connection->table($name)` | `Builder` | Fluent Query Builder |
| `$hydrator->hydrate($class, $row)` | `object` | Row to entity |
| `$hydrator->extract($entity)` | `array` | Entity to row |

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
