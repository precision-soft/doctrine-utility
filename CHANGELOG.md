# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [v4.1.2] - 2026-04-17

### Added

- PHPDoc lifecycle note on `CreatedTrait` and `ModifiedTrait` properties explaining null-until-persisted behaviour
- `@throws` PHPDoc annotations on `AbstractJsonSearch::assertMySQLPlatform()`, `AbstractJsonSearch::parsePathMode()`, and `getSql()` in all DQL function classes (`DateFormat`, `JsonContains`, `JsonContainsPath`, `JsonExtract`, `JsonSearch`, `JsonUnquote`)
- `@throws` PHPDoc annotations on `AbstractRepository` methods (`attachFilters()`, `createQueryBuilder()`, `createQueryBuilderFromFilters()`, `sortFilters()`, `attachJoins()`, `getDoctrineRepository()`, `attachCustomFilters()`, `attachGenericFilters()`, `handleEmptyArrayFilter()`)
- `@throws` PHPDoc annotations on `MysqlLockService` methods (`hasLock()`, `acquire()`, `release()`, `acquireLocks()`, `releaseLocks()`)
- `@return class-string<object>` on `AbstractRepository::getEntityClass()`
- `@param array<string, mixed>` on `AbstractRepository::execute()`
- `@extends EntityRepository<object>` on `DoctrineRepository`
- `@throws Exception` on `JoinCollection::addJoin()`
- Descriptive `attachCustomFilters()` docblock explaining the soft-abstract pattern
- `@info` inline comments on `DateFormat::getSql()`, `AbstractRepository::attachGenericFilters()`, `AbstractRepository::handleEmptyArrayFilter()`, `AbstractRepository::attachJoins()`
- `DateFormatTest::testGetSqlReturnsFormattedStringWithStringExpressions()` — covers the string expression branch in `DateFormat::getSql()`
- `ModifiedTraitTest::testUpdateModifiedTimestampUsesUtcTimezone()` — verifies the UTC timezone is set on the modified timestamp
- `JoinCollectionTest::testAddJoinThrowsOnEmptyAlias()` and `testAddJoinThrowsOnWhitespaceOnlyAlias()` — cover alias validation

### Changed

- `README.md` — documented MySQL as a hard requirement for all features
- `DateFormat::$firstDateExpression` and `$secondDateExpression` — widened type from `Node` to `Node|string` to match `Parser::ArithmeticPrimary()` return type; `getSql()` now dispatches `Node` instances and passes strings through directly
- `AbstractRepository::getConnection()` — added `assert($connection instanceof Connection)` to narrow the return type from `object`
- `AbstractRepository::attachJoins()` — extracted `$alias` into a local variable with `assert(null !== $alias)` to resolve `string|null` PHPStan issue
- `MysqlLockService` — reordered methods so `protected` methods precede `private` methods
- `MysqlLockService::getEntityManager()` — added parentheses around `instanceof` check for consistency; lowercased error message
- `phpstan-baseline.neon` — removed 9 resolved entries from `src/` files (baseline now contains only test-related Mockery entries); incremented 3 test counts for new tests
- Reformatted past CHANGELOG entries for consistency: verb tense normalized to past tense, merged `Removed`/`Tests`/`Dependencies` sections into `Changed`/`Fixed` per Keep a Changelog convention

### Fixed

- `ModifiedTrait` — now uses UTC explicitly (`new DateTime('now', new DateTimeZone('UTC'))`) instead of relying on server timezone
- `JoinCollection::addJoin()` — rejects whitespace-only and `null` alias strings
- `DateFormat::getSql()` — `instanceof` checks now use explicit `true ===` comparison

## [v4.1.1] - 2026-04-14

### Added

- `JoinCollection::addJoin()` — throws `Exception` with message `alias cannot be empty` when `$join->getAlias()` returns an empty string, before the duplicate-alias check

### Changed

- `AbstractJsonSearch` — extracted `assertMySQLPlatform()` helper to centralize the MySQL platform guard previously duplicated in each DQL function
- `DateFormat`, `JsonContains`, `JsonExtract`, `JsonUnquote` — now extend `AbstractJsonSearch` and call `assertMySQLPlatform()` instead of inlining the `instanceof MySQLPlatform` check
- `MysqlLockService` — introduced private `wrapException()` helper; `hasLock()`, `acquire()`, `acquireLocks()` now delegate exception normalization to it, removing three duplicated `try`/`catch` blocks
- `MysqlLockService::acquireLocks()` — on failure, the rollback (`releaseLocks()`) runs through the `wrapException()` `onError` callback instead of an explicit `catch` block
- `MysqlLockService` — reordered properties so `$locks` (protected) precedes `$managerRegistry` (private)
- `phpstan-baseline.neon` — decremented 4 ignore counts in `tests/Function/JsonContainsTest.php` and `tests/Function/JsonUnquoteTest.php` after removing redundant `walkStringPrimary()` mock expectations in the non-MySQL throw tests
- `phpstan/phpstan` bumped from `2.1.46` to `2.1.47`
- `precision-soft/symfony-phpunit` bumped from `v3.2.0` to `v3.2.1`

## [v4.1.0] - 2026-04-12

### Added

- `AbstractRepository` — `@var array<class-string, string>` on `$aliasCache`
- `AbstractRepository` — `@param array<string, mixed> $filters` on `attachFilters()`, `createQueryBuilderFromFilters()`, `attachCustomFilters()`, `attachGenericFilters()`
- `MysqlLockService` — `@param list<string>` on `$lockNames` in `acquireLocks()` and `releaseLocks()`

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
- DQL function exception messages — replaced "method" with "function" (`DateFormat`, `JsonContains`, `JsonContainsPath`, `JsonExtract`, `JsonSearch`, `JsonUnquote`)
- `MysqlLockService` — removed redundant `$locks = []` from constructor; property is now initialized inline
- `SqlWalkerTestTrait` — `createMysqlSqlWalker()`, `createNonMysqlSqlWalker()` visibility widened from `private` to `protected`
- `phpstan-baseline.neon` — removed 7 resolved entries covered by new PHPDoc annotations on `AbstractRepository` and `MysqlLockService`

## [v4.0.5] - 2026-04-10

### Changed

- Renamed test methods `testIsLocked*` → `testHasLock*` to match the actual method name `hasLock`
- `precision-soft/symfony-phpunit` bumped from `v3.1.0` to `v3.1.1`

### Fixed

- `MysqlLockService::release()` — `unset($this->locks[$lockKey])` moved outside the `if ($throwException)` block; lock state was not cleaned up when `$throwException = false`
- `MysqlLockService::release()` — added explicit `null` guard on `RELEASE_LOCK()` response; `null` means the lock was not held by the current thread and is now treated as an error
- `MysqlLockService::release()` — replaced `isset()` with `array_key_exists()` for `lockReleased` key check; `isset` silently missed an explicit `null` value
- `MysqlLockService::acquireLocks()` — re-throws `MysqlLockException` directly instead of wrapping it; original message and code were lost on re-wrap
- `AbstractJsonSearch` — cast `$lexer->lookahead->value` to `(string)` to prevent type errors on non-string token values
- `AbstractRepository::handleEmptyArrayFilter()` — added exhaustive `default` case; unsupported `EmptyArrayFilterBehavior` values now throw an exception instead of being silently ignored

## [v4.0.4] - 2026-04-09

### Added

- `EmptyArrayFilterBehavior` enum (`PrecisionSoft\Doctrine\Utility\Repository`) — `ThrowException` and `MatchNone` cases for controlling how `AbstractRepository::attachGenericFilters()` handles empty array filter values
- `AbstractRepository::getFlags()` — generic, overridable hook returning `array<class-string<\UnitEnum>, \UnitEnum>` for tuning repository behavior; replaces single-purpose flag methods so future flags only require a new enum, no new method
- `AbstractRepository::getLogger()` — overridable hook returning `?LoggerInterface`; absent by default, repositories opt in by overriding
- `psr/log` `^2.0 || ^3.0` declared as explicit `require` (was previously pulled transitively through Doctrine)

### Changed

- `AbstractRepository::attachGenericFilters()` — empty array filter values now route through `handleEmptyArrayFilter()`. Default `EmptyArrayFilterBehavior::MatchNone` emits an always-false marker (`'<filterName>' = '<filterName>-emptyFilter'`) instead of silently dropping the filter. Override `getFlags()` with `EmptyArrayFilterBehavior::ThrowException` for strict validation.
- `AbstractRepository::handleEmptyArrayFilter()` — when behavior is `MatchNone` and `getLogger()` returns a logger, emits a `warning` with `repository`, `filter`, and `hint` context fields
- `AbstractRepository::attachJoins()` — `match` expression replaced with `switch` statement for consistency with the rest of the repository's dispatch style
- `AbstractRepository::getDoctrineRepository()` — exception message uses `static::class` instead of `self::class` so the error points at the actual repository being misused
- `AbstractRepository::attachGenericFilters()` — removed explicit `ArrayParameterType::INTEGER` / `ArrayParameterType::STRING` detection for `IN` clauses; Doctrine ORM resolves the parameter type from entity field metadata

### Fixed

- `AbstractRepository::getAlias()` — `static::$aliasCache` → `self::$aliasCache` (the property is `private static`; late static binding cannot access private members across hierarchy)
- `MySqlWalker::walkFromClause()` — extracted repeated `FROM` clause regex into `FROM_CLAUSE_PATTERN` private constant to remove duplication

## [v4.0.3] - 2026-04-07

### Fixed

- `MysqlLockService::acquire()` — `isset()` → `array_key_exists()` for `lockAcquired` key presence check; handle `null` return from `GET_LOCK()` explicitly before casting to `int`; updated `preparedLockName` in `$this->locks` when re-acquiring an existing lock key
- `MysqlLockService::release()` — added `default` case that throws `MysqlLockException` for unexpected `RELEASE_LOCK()` responses
- `MysqlLockService` — extracted `getEntityManager()` private method to eliminate triplicated EntityManager validation; `prepareLockName()` now accepts `EntityManager` directly instead of re-fetching by name
- `MysqlLockService` — replaced magic MySQL return codes with named constants (`GET_LOCK_SUCCESS`, `GET_LOCK_TIMEOUT`, `IS_FREE_LOCK_FREE`, `RELEASE_LOCK_SUCCESS`, `RELEASE_LOCK_NOT_OWNED`)
- `MysqlLockService::acquire()` / `release()` — `switch ((int)$value)` → `switch (true)` with Yoda case comparisons
- `AbstractRepository::attachGenericFilters()` — skipped empty array filter values instead of generating invalid `IN ()` SQL; detected array element type for `ArrayParameterType::INTEGER` vs `ArrayParameterType::STRING`
- `MySqlWalker` — `self::HINT_*` → `static::HINT_*` for late static binding on index hint constants
- `ModifiedTrait` — replaced multi-line `Note:` comment with inline `@info` docblock

## [v4.0.2] - 2026-04-05

### Changed

- `AbstractRepository::attachJoins()` — replaced `switch` with `match` expression
- `AbstractRepository::attachGenericFilters()` — handle `null` filter values with `IS NULL` clause
- `AbstractJsonSearch` — updated `$lexer->lookahead['value']` to `$lexer->lookahead->value` (Doctrine ORM 3 property access)
- `MySqlWalker` — renamed `validateIndexName()` to `validateIdentifier()` and `INDEX_NAME_PATTERN` to `IDENTIFIER_PATTERN`
- `MysqlLockService` — renamed `isLocked()` to `hasLock()` (boolean query method naming convention)
- `.dev/docker/entrypoint.sh` — skipped `composer install` when `composer.lock` hash matches cached vendor
- Added `@return string[]` PHPDoc to `JoinCollection::getAliases()`
- Added `@param` and `@return` array shape annotations to `AbstractRepository::sortFilters()`
- `SqlWalkerTestTrait` — imported `MockInterface` via `use` instead of inline `Mockery\MockInterface`
- Removed trailing blank lines before closing brace in JSON function test files
- Removed `setAccessible(true)` calls from test files (unnecessary since PHP 8.1)
- Updated `phpstan-baseline.neon`

### Fixed

- `MysqlLockService::acquire()` — removed unreachable `default` branch in switch
- `MysqlLockService::release()` — removed unreachable `default` branch in switch
- `MysqlLockService::releaseLocks()` — copied `$this->locks` to temporary array before iterating to prevent modification during foreach
- `MysqlLockService::release()` — cleaned up stale lock entry from `$this->locks` when release fails and exception is swallowed (`$throwException = false`)

## [v4.0.1] - 2026-04-04

### Changed

- Upgraded from PHPUnit 9 to PHPUnit 11.5 via `precision-soft/symfony-phpunit: ^3.0`
- Replaced `<coverage processUncoveredFiles="true">` with `<source>` element in `phpunit.xml.dist`
- Replaced `<listeners>` with `<extensions>` using `Symfony\Bridge\PhpUnit\SymfonyExtension`
- Added `failOnRisky` and `failOnWarning` attributes to `phpunit.xml.dist`
- Extracted duplicated `createMysqlSqlWalker()` / `createNonMysqlSqlWalker()` into `SqlWalkerTestTrait`
- Migrated `CreatedTraitTest` and `ModifiedTraitTest` from `TestCase` to `AbstractTestCase` with `getMockDto()` pattern
- Replaced PHP DQL function registration snippet with YAML Symfony config in README
- Quoted `$COMPOSER_DEV_MODE` variable in `composer.json` hook script

### Fixed

- `MysqlLockService` — replaced `@var EntityManager` PHPDoc casts with runtime `instanceof` checks and throw `MysqlLockException` on invalid manager
- `MysqlLockService` — rethrew `MysqlLockException` directly in catch blocks instead of wrapping it in a new instance
- `MysqlLockService` — removed unused `Doctrine\DBAL\Connection` import
- `MysqlLockService::releaseLocks()` — passed `throwException: true` to `release()` so non-existent locks are properly caught and re-thrown when requested
- `MysqlLockService` — used specific error messages (`another operation with the same id is already in progress`, `lock was not established by this thread`, `the lock "X" is not currently acquired`) instead of generic `failed acquiring/releasing lock`
- `AbstractRepository::execute()` — used `bindValue()` loop instead of passing parameters to `executeQuery()` (fixes Doctrine DBAL 4 compatibility)
- `MySqlWalker::walkJoinAssociationDeclaration()` — replaced `null !== $ignoreIndex` with `is_array()` type check
- `MySqlWalker::applyIndexHint()` — replaced `null === $indexName` with `is_string()` type check; added null-guard on `preg_replace()` return
- Removed 3 resolved entries from `phpstan-baseline.neon`

## [v4.0.0] - 2026-04-03

### Breaking Changes

- Dropped Doctrine DBAL 3 support (requires DBAL 4)
- Removed `squizlabs/php_codesniffer` dev dependency and `phpcs.xml` configuration
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

[Unreleased]: https://github.com/precision-soft/doctrine-utility/compare/v4.1.2...HEAD

[v4.1.2]: https://github.com/precision-soft/doctrine-utility/compare/v4.1.1...v4.1.2

[v4.1.1]: https://github.com/precision-soft/doctrine-utility/compare/v4.1.0...v4.1.1

[v4.1.0]: https://github.com/precision-soft/doctrine-utility/compare/v4.0.5...v4.1.0

[v4.0.5]: https://github.com/precision-soft/doctrine-utility/compare/v4.0.4...v4.0.5

[v4.0.4]: https://github.com/precision-soft/doctrine-utility/compare/v4.0.3...v4.0.4

[v4.0.3]: https://github.com/precision-soft/doctrine-utility/compare/v4.0.2...v4.0.3

[v4.0.2]: https://github.com/precision-soft/doctrine-utility/compare/v4.0.1...v4.0.2

[v4.0.1]: https://github.com/precision-soft/doctrine-utility/compare/v4.0.0...v4.0.1

[v4.0.0]: https://github.com/precision-soft/doctrine-utility/compare/v3.2.5...v4.0.0
