# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

[4.0.1]: https://github.com/precision-soft/doctrine-utility/compare/v4.0.0...v4.0.1

[4.0.0]: https://github.com/precision-soft/doctrine-utility/compare/v3.2.5...v4.0.0
