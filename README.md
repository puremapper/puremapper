# PureMapper

**PureMapper** is a lightweight **Data Mapper** and **Unit of Work** library for **pure PHP entities**.

It is designed for developers who want:

* Pure PHP domain models (no annotations, no attributes)
* No Active Record, no magic methods
* Clear separation between **domain** and **infrastructure**
* A small, understandable alternative to heavy ORMs
* **Zero external dependencies** - only PHP core + PDO

> *Doctrine ideas, without Doctrine weight.*

---

## Table of Contents

- [Quick Start](#quick-start)
- [Philosophy](#philosophy)
- [Requirements](#requirements)
- [Installation](#installation)
- [Database Connection](#database-connection)
- [Defining Entities](#defining-entities)
- [Mapping with Fluent DSL](#mapping-with-fluent-dsl)
- [Type Conversion](#type-conversion)
- [Relations](#relations)
- [Query Builder](#query-builder)
- [SQL Builder](#sql-builder)
- [Unit of Work](#unit-of-work)
- [Metadata Caching](#metadata-caching)
- [Identity Map](#identity-map)
- [Repository Interface](#repository-interface-optional)
- [Advanced Usage](#advanced-usage)
- [Why PureMapper?](#why-puremapper)
- [When NOT to Use PureMapper](#when-not-to-use-puremapper)
- [Upgrading](#upgrading)
- [Roadmap](#roadmap)
- [License](#license)

---

## Quick Start

```php
// 1. Set up database connection (pure PDO)
use PureMapper\Query\Connection;
use PureMapper\Query\DatabaseDriver;

$pdo = new PDO('mysql:host=localhost;dbname=myapp', 'root', '');
$connection = new Connection($pdo, DatabaseDriver::MySQL);

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

$em->persist($user);
$em->commit(); // INSERT executed, $user->id populated
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
   SqlBuilder + Connection (PDO)
         |
      Database
```

---

## Requirements

* PHP **8.1+**
* PDO extension (`ext-pdo`)

> PureMapper has **zero external dependencies**. Only PHP core and PDO are required.

---

## Installation

```bash
composer require puremapper/puremapper
```

---

## Database Connection

PureMapper uses PDO directly with a thin wrapper for database abstraction.

### Supported Databases

| Database   | Driver Enum              | Identifier Quote |
|------------|--------------------------|------------------|
| MySQL      | `DatabaseDriver::MySQL`  | Backtick (`)     |
| PostgreSQL | `DatabaseDriver::PostgreSQL` | Double quote (") |
| SQLite     | `DatabaseDriver::SQLite` | Double quote (") |

### Connection Setup

```php
use PDO;
use PureMapper\Query\Connection;
use PureMapper\Query\DatabaseDriver;

// MySQL
$pdo = new PDO('mysql:host=localhost;dbname=myapp', 'user', 'password', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$connection = new Connection($pdo, DatabaseDriver::MySQL);

// PostgreSQL
$pdo = new PDO('pgsql:host=localhost;dbname=myapp', 'user', 'password');
$connection = new Connection($pdo, DatabaseDriver::PostgreSQL);

// SQLite
$pdo = new PDO('sqlite:/path/to/database.db');
$connection = new Connection($pdo, DatabaseDriver::SQLite);

// SQLite in-memory (for testing)
$pdo = new PDO('sqlite::memory:');
$connection = new Connection($pdo, DatabaseDriver::SQLite);
```

### Connection Methods

| Method | Returns | Description |
|--------|---------|-------------|
| `select(CompiledQuery)` | `array` | Execute SELECT, return rows as assoc arrays |
| `execute(CompiledQuery)` | `int` | Execute INSERT/UPDATE/DELETE, return affected rows |
| `insert(CompiledQuery)` | `string` | Execute INSERT, return last insert ID |
| `table(string)` | `SqlBuilder` | Create query builder for table |
| `beginTransaction()` | `void` | Start transaction |
| `commit()` | `void` | Commit transaction |
| `rollBack()` | `void` | Rollback transaction |
| `getPdo()` | `PDO` | Get underlying PDO instance |
| `statement(string)` | `bool` | Execute raw SQL (DDL) |

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
    ->with('posts', 'profile', 'roles')
    ->where('status', '=', 'active')
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

---

## SQL Builder

PureMapper includes a minimal SQL Builder for direct database operations.

### Basic Usage

```php
// SELECT
$query = $connection
    ->table('users')
    ->select('id', 'name', 'email')
    ->where('status', '=', 'active')
    ->whereIn('role', ['admin', 'editor'])
    ->orderBy('created_at', 'DESC')
    ->limit(10)
    ->toSelect();

$rows = $connection->select($query);
// $query->sql = 'SELECT "id", "name", "email" FROM "users" WHERE "status" = ? AND "role" IN (?, ?) ORDER BY "created_at" DESC LIMIT 10'
// $query->params = ['active', 'admin', 'editor']

// INSERT
$query = $connection
    ->table('users')
    ->toInsert(['name' => 'John', 'email' => 'john@example.com']);

$lastId = $connection->insert($query);

// UPDATE
$query = $connection
    ->table('users')
    ->where('id', '=', 1)
    ->toUpdate(['name' => 'Jane']);

$affected = $connection->execute($query);

// DELETE
$query = $connection
    ->table('users')
    ->where('id', '=', 1)
    ->toDelete();

$affected = $connection->execute($query);
```

### SqlBuilder Methods

| Method | Description |
|--------|-------------|
| `table(string)` | Set table name |
| `select(string...)` | Set columns to select (default: `*`) |
| `where(column, operator, value)` | Add AND WHERE condition |
| `orWhere(column, operator, value)` | Add OR WHERE condition |
| `whereIn(column, array)` | Add WHERE IN condition |
| `orderBy(column, direction)` | Add ORDER BY clause |
| `limit(int)` | Set LIMIT |
| `offset(int)` | Set OFFSET |
| `join(table, first, operator, second)` | Add INNER JOIN |
| `leftJoin(table, first, operator, second)` | Add LEFT JOIN |
| `toSelect()` | Compile SELECT query |
| `toInsert(array)` | Compile INSERT query |
| `toUpdate(array)` | Compile UPDATE query |
| `toDelete()` | Compile DELETE query |

### CompiledQuery

All compile methods return a `CompiledQuery` object:

```php
final readonly class CompiledQuery
{
    public function __construct(
        public string $sql,    // SQL with placeholders
        public array $params,  // Bound parameters
    ) {}
}
```

---

## Unit of Work

The Unit of Work tracks entity state and coordinates persistence.

```php
// Create new entities
$user = new User();
$user->name = 'John';
$em->persist($user);

// Modify existing entities (explicit dirty marking)
$user->email = 'new@example.com';
$em->markDirty($user);

// Remove entities
$em->remove($user);

// Commit all changes in a transaction
$em->commit();
```

### Change Tracking

PureMapper uses **explicit change tracking**. You must call `markDirty()` on modified entities:

```php
$user = $em->query(User::class)->find(1);
$user->name = 'Updated Name';
$em->markDirty($user);  // Required to trigger UPDATE
$em->commit();
```

This design is intentional - no hidden magic, no unexpected queries.

### Transaction Control

By default, `commit()` wraps all operations in a transaction. For manual control:

```php
// Auto transaction (default)
$em->commit();

// Manual transaction control
$em->getUnitOfWork()->setAutoTransaction(false);
$connection->beginTransaction();
try {
    $em->commit();
    $connection->commit();
} catch (Exception $e) {
    $connection->rollBack();
    throw $e;
}
```

---

## Metadata Caching

For production environments, PureMapper supports PSR-16 metadata caching.

```php
use PureMapper\Mapping\MetadataRegistry;
use PureMapper\Mapping\CachedMetadataRegistry;

// Development - no caching
$registry = new MetadataRegistry();

// Production - with PSR-16 cache
$cachedRegistry = new CachedMetadataRegistry(
    $registry,
    $cache,  // PSR-16 CacheInterface
    prefix: 'puremapper_metadata_',
    ttl: 3600,
);

// Warm cache during deployment
$cachedRegistry->warm();

// Invalidate when mappings change
$cachedRegistry->invalidate(User::class);
$cachedRegistry->invalidateAll();
```

---

## Identity Map

The Unit of Work maintains an **identity map** to ensure:

* Same database row always returns the same object instance
* Circular references are handled correctly
* Entity identity is preserved across operations

```php
$user1 = $em->query(User::class)->find(1);
$user2 = $em->query(User::class)->find(1);

assert($user1 === $user2); // Same instance
```

The identity map is cleared after `commit()` or `clear()`.

---

## Repository Interface (OPTIONAL)

PureMapper provides a repository interface. Implementation is yours:

```php
use PureMapper\Repository\RepositoryInterface;

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

### Raw SQL Queries

For complex queries, use the Connection directly:

```php
// Raw SELECT
$rows = $connection->select(
    new CompiledQuery(
        'SELECT u.*, COUNT(p.id) as post_count FROM users u LEFT JOIN posts p ON p.user_id = u.id GROUP BY u.id',
        []
    )
);

// Hydrate results
$hydrator = $em->getHydrator();
$users = array_map(
    fn($row) => $hydrator->hydrate(User::class, $row),
    $rows
);

// DDL statements
$connection->statement('CREATE INDEX idx_users_email ON users(email)');
```

### Custom TypeConverter

```php
use PureMapper\Type\TypeConverter;

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

$typeRegistry->register('uuid', new UuidConverter());

$mapper = (new EntityMapper(Product::class))
    ->table('products')
    ->id('id', 'uuid')
    ->field('name', 'string')
    ->build();
```

---

## Why PureMapper?

| Feature                   | PureMapper | Doctrine | Eloquent |
|---------------------------|------------|----------|----------|
| Pure entities             | Yes        | Partial  | No       |
| No annotations/attributes | Yes        | No       | No       |
| Zero dependencies         | Yes        | No       | No       |
| Lightweight               | Yes        | No       | No       |
| Explicit mapping          | Yes        | Partial  | No       |
| Framework agnostic        | Yes        | Yes      | No       |
| Explicit change tracking  | Yes        | No       | No       |
| No proxy generation       | Yes        | No       | Yes      |

---

## When NOT to Use PureMapper

PureMapper is intentionally minimal. Do not use it if you need:

* Automatic graph synchronization
* Schema migrations (use dedicated migration tools)
* Automatic dirty checking
* Lazy loading
* Complex inheritance mapping
* Query caching

---

## Upgrading

See [UPGRADE.md](UPGRADE.md) for migration guides between major versions.

---

## Roadmap

### Completed

* [x] Metadata Caching (PSR-16 support)
* [x] Pure PDO (removed illuminate/database dependency)

### Planned

* [ ] Event dispatching (prePersist, postPersist, preUpdate, postUpdate, preRemove, postRemove, postLoad)
* [ ] Embedded/Value Objects
* [ ] Soft Deletes

---

## License

MIT
