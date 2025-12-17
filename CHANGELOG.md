# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.1.0] - 2025-12-17

### Added

- **Nested Where Conditions**: Support for nested where conditions using closures, enabling complex query building:
  ```php
  $query->where('status', 'active')
        ->where(function($q) {
            $q->where('role', 'admin')
              ->orWhere('role', 'moderator');
        });
  ```

### Fixed

- **PHPStan Compatibility**: Added proper type hints for `whereIn` and `__call` methods to improve static analysis support

## [2.0.0] - 2025-12-16

### Added

- **Pure PDO SqlBuilder**: Complete SQL query builder implementation with zero dependencies
  - Support for MySQL, PostgreSQL, and SQLite with proper identifier quoting
  - Fluent API: `where()`, `orWhere()`, `whereIn()`, `whereNull()`, `whereNotNull()`
  - Join support: `join()`, `leftJoin()` with compound identifier handling
  - Explicit compilation methods: `toSelect()`, `toInsert()`, `toUpdate()`, `toDelete()`
- **DatabaseDriver enum**: Type-safe database driver selection
- **SQL injection prevention**: Operator and direction validation with whitelisting
- **Closure-based hydration**: High-performance entity hydration without reflection overhead
- **O(1) lookup maps**: Precomputed property-to-column and column-to-property mappings
- **`whereNull()` and `whereNotNull()`**: Query builder methods for NULL checks
- **`__call` proxy pattern**: Clean delegation from EntityQuery to SqlBuilder

### Changed

- **BREAKING**: Removed `illuminate/database` dependency - now pure PDO
- **BREAKING**: New `Connection` class replaces Illuminate's ConnectionInterface
- **BREAKING**: `SqlBuilder` requires `DatabaseDriver` enum for proper identifier quoting
- **BREAKING**: Query compilation is now explicit (`toSelect()` instead of implicit `toSql()`)
- Optimized entity scheduling with `spl_object_id` keyed arrays
- Improved identifier assignment using closure-based setters

### Removed

- **BREAKING**: `illuminate/database` dependency and all related code
- Duplicate methods in EntityQuery (now delegated via `__call`)

### Security

- Added SQL injection prevention with operator/direction validation
- All user-provided operators are validated against a whitelist

## [1.0.1] - 2025-01-XX

### Fixed

- Minor bug fixes and improvements

## [1.0.0] - 2025-01-XX

### Added

- Initial release
- Entity Manager with Unit of Work pattern
- Identity Map for entity tracking
- Fluent entity mapping DSL
- Type conversion system with built-in converters
- Relation support: hasOne, hasMany, belongsTo, manyToMany
- Eager loading to prevent N+1 queries
- PSR-16 metadata caching support
- Composite primary key support
