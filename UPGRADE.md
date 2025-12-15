# Upgrade Guide

## Upgrading from v1.x to v2.x

### Breaking Changes

Version 2.0 removes the `illuminate/database` dependency in favor of a pure PDO solution. This is a **breaking change** that affects how you create database connections.

### Step 1: Update Composer

```bash
composer update puremapper/puremapper
```

The `illuminate/database` package will be automatically removed if no other packages depend on it.

### Step 2: Update Connection Setup

**Before (v1.x):**

```php
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
$em = new EntityManager($connection, $registry, $typeRegistry);
```

**After (v2.x):**

```php
use PDO;
use PureMapper\Query\Connection;
use PureMapper\Query\DatabaseDriver;

$pdo = new PDO(
    'mysql:host=localhost;dbname=myapp;charset=utf8mb4',
    'root',
    '',
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

$connection = new Connection($pdo, DatabaseDriver::MySQL);
$em = new EntityManager($connection, $registry, $typeRegistry);
```

### Step 3: Update Raw Query Usage

If you were using Illuminate's Query Builder directly for raw queries, update to use the new SqlBuilder.

**Before (v1.x):**

```php
// Direct table operations
$connection->table('users')->insert(['name' => 'John', 'email' => 'john@example.com']);
$id = $connection->table('users')->insertGetId(['name' => 'John']);
$rows = $connection->table('users')->where('status', 'active')->get();
$count = $connection->table('users')->count();

// Raw queries
$rows = $connection->select('SELECT * FROM users WHERE id = ?', [1]);
```

**After (v2.x):**

```php
use PureMapper\Query\CompiledQuery;

// Using SqlBuilder
$query = $connection->table('users')->toInsert(['name' => 'John', 'email' => 'john@example.com']);
$connection->execute($query);

$query = $connection->table('users')->toInsert(['name' => 'John']);
$id = $connection->insert($query);

$query = $connection->table('users')->where('status', '=', 'active')->toSelect();
$rows = $connection->select($query);

// For count, use raw query
$rows = $connection->select(new CompiledQuery('SELECT COUNT(*) as cnt FROM users', []));
$count = (int) $rows[0]['cnt'];

// Raw queries
$rows = $connection->select(new CompiledQuery('SELECT * FROM users WHERE id = ?', [1]));
```

### Step 4: Update Type Hints

If you type-hinted `ConnectionInterface`, update to use `Connection`:

**Before (v1.x):**

```php
use Illuminate\Database\ConnectionInterface;

class MyService
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {}
}
```

**After (v2.x):**

```php
use PureMapper\Query\Connection;

class MyService
{
    public function __construct(
        private Connection $connection,
    ) {}
}
```

### Step 5: Update Transaction Usage

Transaction methods remain similar but are now on the Connection class:

```php
// Same API
$connection->beginTransaction();
$connection->commit();
$connection->rollBack();

// Access underlying PDO if needed
$pdo = $connection->getPdo();
```

### New Features in v2.x

#### DatabaseDriver Enum

Specify your database type for correct identifier quoting:

```php
use PureMapper\Query\DatabaseDriver;

// MySQL uses backticks: `column`
$connection = new Connection($pdo, DatabaseDriver::MySQL);

// PostgreSQL/SQLite use double quotes: "column"
$connection = new Connection($pdo, DatabaseDriver::PostgreSQL);
$connection = new Connection($pdo, DatabaseDriver::SQLite);
```

#### CompiledQuery Object

Queries are now compiled to immutable objects with SQL and parameters separated:

```php
$query = $connection
    ->table('users')
    ->where('status', '=', 'active')
    ->toSelect();

echo $query->sql;    // SELECT * FROM "users" WHERE "status" = ?
print_r($query->params); // ['active']
```

#### SqlBuilder Methods

New fluent builder with explicit compile methods:

| Method | Description |
|--------|-------------|
| `table(string)` | Set table name |
| `select(string...)` | Set columns (default: *) |
| `where(col, op, val)` | Add AND WHERE |
| `orWhere(col, op, val)` | Add OR WHERE |
| `whereIn(col, array)` | Add WHERE IN |
| `orderBy(col, dir)` | Add ORDER BY |
| `limit(int)` | Set LIMIT |
| `offset(int)` | Set OFFSET |
| `join(...)` | Add INNER JOIN |
| `leftJoin(...)` | Add LEFT JOIN |
| `toSelect()` | Compile to SELECT |
| `toInsert(array)` | Compile to INSERT |
| `toUpdate(array)` | Compile to UPDATE |
| `toDelete()` | Compile to DELETE |

### Benefits of v2.x

1. **Zero Dependencies**: Only requires PHP core + PDO
2. **Smaller Package**: No transitive dependencies from illuminate/database
3. **Faster Installation**: Fewer packages to download
4. **Framework Agnostic**: Works anywhere PHP runs
5. **Transparent SQL**: See exactly what queries are generated

### Getting Help

If you encounter issues during upgrade:

1. Check that your PHP version is 8.1+
2. Ensure PDO extension is enabled
3. Verify the DatabaseDriver matches your database
4. Review the [README](README.md) for updated examples

For bugs or questions, open an issue on GitHub.
