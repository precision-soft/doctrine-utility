# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [v4.1.0] - 2026-04-12

### Changed

- `AbstractRepository` — removed `final` from all 11 method declarations (`setManagerRegistry`, `refresh`, `attachFilters`, `getManager`, `execute`, `getConnection`, `createQueryBuilder`, `createQueryBuilderFromFilters`, `sortFilters`, `attachJoins`, `getDoctrineRepository`)
- `AbstractRepository` — `$aliasCache` visibility widened from `private static` to `protected static`; `$managerRegistry` from `private` to `protected`
- `AbstractRepository` — `attachGenericFilters()`, `handleEmptyArrayFilter()`, `getFlag()` visibility widened from `private` to `protected`
- `DoctrineRepository` — removed `final` from `hasField()`
- `CreatedTrait` — `$created` visibility widened from `private` to `protected`
- `ModifiedTrait` — `$modified` visibility widened from `private` to `protected`
- `MysqlLockService` — `$locks` visibility widened from `private` to `protected`; `buildLockKey()`, `prepareLockName()`, `getEntityManager()` from `private` to `protected`; 5 constants from `private const` to `protected const`
- `MySqlWalker` — `applyIndexHint()`, `validateIdentifier()` visibility widened from `private` to `protected`; 2 constants from `private const` to `protected const`
- `JoinCollection::addJoin()` — return type changed from `self` to `static` for fluent extensibility
- DQL functions exception messages — "method" replaced with "function" (DateFormat, JsonContains, JsonContainsPath, JsonExtract, JsonSearch, JsonUnquote)
- `MysqlLockService` — remove redundant `$locks = []` from constructor; property is now initialized inline
- `SqlWalkerTestTrait` — `createMysqlSqlWalker()`, `createNonMysqlSqlWalker()` visibility widened from `private` to `protected`

### Added

- `AbstractRepository` — `@var array<class-string, string>` on `$aliasCache`
- `AbstractRepository` — `@param array<string, mixed> $filters` on `attachFilters()`, `createQueryBuilderFromFilters()`, `attachCustomFilters()`, `attachGenericFilters()`
- `MysqlLockService` — `@param list<string>` on `$lockNames` in `acquireLocks()` and `releaseLocks()`

### Removed

- `phpstan-baseline.neon` — remove 7 resolved entries (covered by new PHPDoc annotations on `AbstractRepository` and `MysqlLockService`)

## [v4.0.5] - 2026-04-10

### Fixed

- `MysqlLockService::release()` — `unset($this->locks[$lockKey])` moved outside the `if ($throwException)` block; lock state was not cleaned up when `$throwException = false`
- `MysqlLockService::release()` — added explicit `null` guard on `RELEASE_LOCK()` response; `null` means the lock was not held by the current thread and is now treated as an error
- `MysqlLockService::release()` — replaced `isset()` with `array_key_exists()` for `lockReleased` key check; `isset` silently missed an explicit `null` value
- `MysqlLockService::acquireLocks()` — re-throw `MysqlLockException` directly instead of wrapping it; original message and code were lost on re-wrap
- `AbstractJsonSearch` — cast `$lexer->lookahead->value` to `(string)` to prevent type errors on non-string token values
- `AbstractRepository::handleEmptyArrayFilter()` — added exhaustive `default` case; unsupported `EmptyArrayFilterBehavior` values now throw an exception instead of being silently ignored

### Tests

- Renamed test methods `testIsLocked*` → `testHasLock*` to match the actual method name `hasLock`

### Dependencies

- `precision-soft/symfony-phpunit` bumped from `v3.1.0` to `v3.1.1`

## [v4.0.4] - 2026-04-09

### Added

- `EmptyArrayFilterBehavior` enum (`PrecisionSoft\Doctrine\Utility\Repository`) — `ThrowException` and `MatchNone` cases for controlling how `AbstractRepository::attachGenericFilters()` handles empty array filter values
- `AbstractRepository::getFlags()` — generic, overridable hook returning `array<class-string<\UnitEnum>, \UnitEnum>` for tuning repository behavior; replaces single-purpose flag methods so future flags only require a new enum, no new method
- `AbstractRepository::getLogger()` — overridable hook returning `?LoggerInterface`; absent by default, repositories opt in by overriding
- `psr/log` `^2.0 || ^3.0` declared as explicit `require` (was previously pulled transitively through Doctrine)

### Changed

- `AbstractRepository::attachGenericFilters()` — empty array filter values now route through `handleEmptyArrayFilter()`. Default `EmptyArrayFilterBehavior::MatchNone` emits an always-false marker (`'<filterName>' = '<filterName>-emptyFilter'`) instead of silently dropping the filter, preserving legacy "match no rows" semantics while making the fallback grep-able in query logs. Override `getFlags()` with `EmptyArrayFilterBehavior::ThrowException` for strict validation.
- `AbstractRepository::handleEmptyArrayFilter()` — when behavior is `MatchNone` and `getLogger()` returns a logger, emits a `warning` with `repository`, `filter`, and `hint` context fields so empty-array call sites are observable without requiring strict mode
- `AbstractRepository::attachJoins()` — `match` expression → `switch` statement for consistency with the rest of the abstract repository's dispatch style
- `AbstractRepository::getDoctrineRepository()` — exception message uses `static::class` (resolved subclass) instead of `self::class` so the error points at the actual repository being misused

### Fixed

- `AbstractRepository::getAlias()` — `static::$aliasCache` → `self::$aliasCache` (the property is `private static`; late static binding cannot access private members across hierarchy)
- `MySqlWalker::walkFromClause()` — extract repeated `FROM` clause regex into `FROM_CLAUSE_PATTERN` private constant to remove duplication

### Removed

- `AbstractRepository::attachGenericFilters()` — drop explicit `ArrayParameterType::INTEGER` / `ArrayParameterType::STRING` detection for `IN` clauses; Doctrine ORM resolves the parameter type from entity field metadata, making the explicit branch added in `4.0.3` redundant

## [v4.0.3] - 2026-04-07

### Fixed

- `MysqlLockService::acquire()` — `isset()` → `array_key_exists()` for `lockAcquired` key presence check; handle `null` return from `GET_LOCK()` explicitly before casting to `int`; update `preparedLockName` in `$this->locks` when re-acquiring an existing lock key
- `MysqlLockService::release()` — add `default` case that throws `MysqlLockException` for unexpected `RELEASE_LOCK()` responses
- `MysqlLockService` — extract `getEntityManager()` private method to eliminate triplicated EntityManager validation; `prepareLockName()` now accepts `EntityManager` directly instead of re-fetching by name
- `MysqlLockService` — replace magic MySQL return codes with named constants (`GET_LOCK_SUCCESS`, `GET_LOCK_TIMEOUT`, `IS_FREE_LOCK_FREE`, `RELEASE_LOCK_SUCCESS`, `RELEASE_LOCK_NOT_OWNED`)
- `MysqlLockService::acquire()` / `release()` — `switch ((int)$value)` → `switch (true)` with Yoda case comparisons
- `AbstractRepository::attachGenericFilters()` — skip empty array filter values instead of generating invalid `IN ()` SQL; detect array element type for `ArrayParameterType::INTEGER` vs `ArrayParameterType::STRING`
- `MySqlWalker` — `self::HINT_*` → `static::HINT_*` for late static binding on index hint constants
- `ModifiedTrait` — replace multi-line `Note:` comment with inline `@info` docblock

## [v4.0.2] - 2026-04-05

### Fixed

- `MysqlLockService::acquire()` — remove unreachable `default` branch in switch (MySQL `GET_LOCK()` only returns `0`, `1`, or `NULL`; `NULL` is caught by `isset()` guard)
- `MysqlLockService::release()` — remove unreachable `default` branch in switch (same reason)
- `MysqlLockService::releaseLocks()` — copy `$this->locks` to temporary array before iterating to prevent modification during foreach
- `MysqlLockService::release()` — clean up stale lock entry from `$this->locks` when release fails and exception is swallowed (`$throwException = false`)

### Changed

- `AbstractRepository::attachJoins()` — replace `switch` with `match` expression
- `AbstractRepository::attachGenericFilters()` — handle `null` filter values with `IS NULL` clause
- `AbstractJsonSearch` — update `$lexer->lookahead['value']` to `$lexer->lookahead->value` (Doctrine ORM 3 property access)
- `MySqlWalker` — rename `validateIndexName()` to `validateIdentifier()` and `INDEX_NAME_PATTERN` to `IDENTIFIER_PATTERN`
- `MysqlLockService` — rename `isLocked()` to `hasLock()` (boolean query method naming convention)
- `.dev/docker/entrypoint.sh` — skip `composer install` when `composer.lock` hash matches cached vendor
- Add `@return string[]` PHPDoc to `JoinCollection::getAliases()`
- Add `@param` and `@return` array shape annotations to `AbstractRepository::sortFilters()`
- `SqlWalkerTestTrait` — import `MockInterface` via `use` instead of inline `Mockery\MockInterface`
- Remove trailing blank lines before closing brace in JSON function test files
- Remove `setAccessible(true)` calls from test files (unnecessary since PHP 8.1)
- Update `phpstan-baseline.neon`

## [v4.0.1] - 2026-04-04

### Fixed

- `MysqlLockService` — replace `@var EntityManager` PHPDoc casts with runtime `instanceof` checks and throw `MysqlLockException` on invalid manager
- `MysqlLockService` — rethrow `MysqlLockException` directly in catch blocks instead of wrapping it in a new instance
- `MysqlLockService` — remove unused `Doctrine\DBAL\Connection` import
- `MysqlLockService::releaseLocks()` — pass `throwException: true` to `release()` so non-existent locks are properly caught and re-thrown when requested
- `MysqlLockService` — use specific error messages (`another operation with the same id is already in progress`, `lock was not established by this thread`, `the lock "X" is not currently acquired`) instead of generic `failed acquiring/releasing lock`
- `AbstractRepository::execute()` — use `bindValue()` loop instead of passing parameters to `executeQuery()` (fixes Doctrine DBAL 4 compatibility)
- `MySqlWalker::walkJoinAssociationDeclaration()` — replace `null !== $ignoreIndex` with `is_array()` type check
- `MySqlWalker::applyIndexHint()` — replace `null === $indexName` with `is_string()` type check and add null-guard on `preg_replace()` return
- Remove 3 resolved entries from `phpstan-baseline.neon`

### Changed

- Upgrade from PHPUnit 9 to PHPUnit 11.5 via `precision-soft/symfony-phpunit: ^3.0`
- Replace `<coverage processUncoveredFiles="true">` with `<source>` element in `phpunit.xml.dist`
- Replace `<listeners>` with `<extensions>` using `Symfony\Bridge\PhpUnit\SymfonyExtension`
- Add `failOnRisky` and `failOnWarning` attributes to `phpunit.xml.dist`
- Extract duplicated `createMysqlSqlWalker()` / `createNonMysqlSqlWalker()` into `SqlWalkerTestTrait`
- Migrate `CreatedTraitTest` and `ModifiedTraitTest` from `TestCase` to `AbstractTestCase` with `getMockDto()` pattern
- Replace PHP DQL function registration snippet with YAML Symfony config in README
- Quote `$COMPOSER_DEV_MODE` variable in `composer.json` hook script

## [v4.0.0] - 2026-04-03

### Breaking Changes

- Dropped Doctrine DBAL 3 support (requires DBAL 4)
- Removed `squizlabs/php_codesniffer` from dev dependencies
- Renamed `phpunit.xml` to `phpunit.xml.dist`

### Added

- PHPStan level 8 with baseline
- Test classes: `DateFormatTest`, `JsonContainsTest`, `JsonContainsPathTest`, `JsonExtractTest`, `JsonSearchTest`, `JsonUnquoteTest`, `DoctrineRepositoryTest`, `MysqlLockServiceTest`, `MySqlWalkerTest`, `CreatedTraitTest`, `ModifiedTraitTest`, `ExceptionTest`, `JoinCollectionTest`, `AbstractJsonSearchTest`

### Changed

- Upgraded `precision-soft/symfony-phpunit` from `1.*` to `2.*`
- Upgraded PHPStan from `^1.0` to `^2.0`
- Replaced `php_codesniffer` with PHPStan for static analysis
- Descriptive variable names across all source and test files
- `AbstractRepositoryTest` extends `AbstractTestCase` from `precision-soft/symfony-phpunit`

### Removed

- `squizlabs/php_codesniffer` dev dependency
- `phpcs.xml` configuration file

[v4.1.0]: https://github.com/precision-soft/doctrine-utility/compare/v4.0.5...v4.1.0

[v4.0.5]: https://github.com/precision-soft/doctrine-utility/compare/v4.0.4...v4.0.5

[v4.0.4]: https://github.com/precision-soft/doctrine-utility/compare/v4.0.3...v4.0.4

[v4.0.3]: https://github.com/precision-soft/doctrine-utility/compare/v4.0.2...v4.0.3

[v4.0.2]: https://github.com/precision-soft/doctrine-utility/compare/v4.0.1...v4.0.2

[v4.0.1]: https://github.com/precision-soft/doctrine-utility/compare/v4.0.0...v4.0.1

[v4.0.0]: https://github.com/precision-soft/doctrine-utility/compare/v3.2.5...v4.0.0
