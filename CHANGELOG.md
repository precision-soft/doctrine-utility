# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [4.0.3] - 2026-04-07

### Fixed

- `MysqlLockService::acquire()` — `isset()` → `array_key_exists()` for `lockAcquired` key presence check; handle `null` return from `GET_LOCK()` explicitly before casting to `int`; update `preparedLockName` in `$this->locks` when re-acquiring an existing lock key
- `MysqlLockService::release()` — add `default` case that throws `MysqlLockException` for unexpected `RELEASE_LOCK()` responses
- `MysqlLockService` — extract `getEntityManager()` private method to eliminate triplicated EntityManager validation; `prepareLockName()` now accepts `EntityManager` directly instead of re-fetching by name
- `MysqlLockService` — replace magic MySQL return codes with named constants (`GET_LOCK_SUCCESS`, `GET_LOCK_TIMEOUT`, `IS_FREE_LOCK_FREE`, `RELEASE_LOCK_SUCCESS`, `RELEASE_LOCK_NOT_OWNED`)
- `MysqlLockService::acquire()` / `release()` — `switch ((int)$value)` → `switch (true)` with Yoda case comparisons
- `AbstractRepository::attachGenericFilters()` — skip empty array filter values instead of generating invalid `IN ()` SQL; detect array element type for `ArrayParameterType::INTEGER` vs `ArrayParameterType::STRING`
- `MySqlWalker` — `self::HINT_*` → `static::HINT_*` for late static binding on index hint constants
- `ModifiedTrait` — replace multi-line `Note:` comment with inline `@info` docblock

## [4.0.2] - 2026-04-05

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

## [4.0.1] - 2026-04-04

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

## [4.0.0] - 2026-04-03

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

[4.0.3]: https://github.com/precision-soft/doctrine-utility/compare/v4.0.2...v4.0.3

[4.0.2]: https://github.com/precision-soft/doctrine-utility/compare/v4.0.1...v4.0.2

[4.0.1]: https://github.com/precision-soft/doctrine-utility/compare/v4.0.0...v4.0.1

[4.0.0]: https://github.com/precision-soft/doctrine-utility/compare/v3.2.5...v4.0.0
