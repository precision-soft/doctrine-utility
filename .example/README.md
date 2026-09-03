# Doctrine Utility — example

A product catalogue — categories, products, currencies — built on `precision-soft/doctrine-utility` and run against MySQL 8.4, MariaDB 11.8 and PostgreSQL 18. Paths in this file are relative to `.example/`.

## What it represents

- `src/Entity/` — `Product` (a uuid identity, a price in minor units, a json attributes document, `CreatedTrait` + `ModifiedTrait` under `HasLifecycleCallbacks`), `Category` and `Currency`, every one a `DoctrineRepository` entity.
- `src/Repository/ProductRepository.php` — the catalogue's read service on `AbstractRepository`: generic filters from the mapping, the custom `nameLike` filter, the category join through `JoinCollection` and the `categoryName` filter that stands on it, typed criteria pages, the six DQL functions over the attributes, and the barcode lookup with a `MySqlWalker` index hint.
- `src/Service/LockServiceFactory.php` — picks `MysqlLockService` or `PostgresqlLockService` by the connection's platform, as a container alias would in an application.
- `src/Service/ProductRepricing.php` — a price change as a critical section under a named lock: one product, or many in one sorted acquisition.

## What each test shows

| Test file                                | Library capability demonstrated                                                                                                                                                                                                                                            |
|------------------------------------------|----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `tests/Functional/ArrayFilterTest.php`   | the array filter API — a mapped field or association is a generic filter, a uid binds through the column's type alone and in a list, `null` is `IS NULL`, an empty list matches nothing or throws by flag, custom filters and the join declared in `attachCustomFilters()` |
| `tests/Functional/TypedCriteriaTest.php` | the typed criteria API — the eleven operators, a uid and an association compared by what the column holds, a keyset walked by price to the last page, and the refusals: a nullable sort column under a keyset, a bound `null`, `LIKE` on an association                    |
| `tests/Functional/JsonFunctionTest.php`  | `JSON_CONTAINS`, `JSON_EXTRACT` + `JSON_UNQUOTE`, `JSON_CONTAINS_PATH`, `JSON_SEARCH` and `DATE_FORMAT` in DQL, on the MySQL family                                                                                                                                        |
| `tests/Functional/IndexHintTest.php`     | `MySqlWalker` `FORCE INDEX` / `IGNORE INDEX` on the barcode lookup, proved by `EXPLAIN`                                                                                                                                                                                    |
| `tests/Functional/RepricingLockTest.php` | `LockServiceInterface` on all three engines — a reprice holds the lock only while it runs, a second session is refused while the first holds the product, `acquireLocks()` / `releaseLocks()` around many products, `ModifiedTrait` stamping the update                    |

## How to run

From the repository root, with the databases up:

```bash
.dev/validate/all.sh --example
```

or by hand, inside the dev container:

```bash
cd .example && composer install && composer check
```

`composer.lock` is not committed: the example installs the library from the working tree through a path repository, so it always tests the code as it stands. `composer test` runs with `--fail-on-skipped`, so a database that is not there is a failure, not a skip. The root's `composer cs-check` covers this directory; `phpstan.neon` includes `../.dev/phpstan/rules.neon`, so the house rules apply here too. The directory is `export-ignore`d and never reaches a consumer's `vendor/`.
