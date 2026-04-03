# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

[4.0.0]: https://github.com/precision-soft/doctrine-utility/compare/v3.2.5...v4.0.0
