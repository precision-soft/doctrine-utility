# Doctrine Utility

[![ci](https://github.com/precision-soft/doctrine-utility/actions/workflows/ci.yml/badge.svg)](https://github.com/precision-soft/doctrine-utility/actions/workflows/ci.yml)
[![PHP >= 8.2](https://img.shields.io/badge/php-%3E%3D8.2-8892BF)](https://www.php.net/)
[![PHPStan Level 8](https://img.shields.io/badge/phpstan-level%208-brightgreen)](https://phpstan.org/)
[![Code Style PER-CS2.0](https://img.shields.io/badge/code%20style-PER--CS2.0-blue)](https://www.php-fig.org/per/coding-style/)
[![License MIT](https://img.shields.io/badge/license-MIT-green)](LICENSE)

Doctrine repository, function and locking utilities for **MySQL**, **MariaDB** and **PostgreSQL**.

**You may fork and modify it as you wish.**

Any suggestions are welcomed.

## Requirements

- PHP 8.2+
- Doctrine ORM 3
- Doctrine DBAL 4
- MySQL **or MariaDB** for the DQL functions, `MySqlWalker` and `MysqlLockService`; `PostgresqlLockService` requires PostgreSQL instead. `AbstractRepository`, `DoctrineRepository` and the entity traits carry no platform specific SQL and run on any of the three
- The integration suite runs against MySQL 8.4, MariaDB 11.4 and PostgreSQL 17

## Installation

```shell
composer require precision-soft/doctrine-utility
```

## Usage for `AbstractRepository` and `DoctrineRepository`

The purposes for these classes are:

- easier constructor injection for the repositories; the quotes are because these repositories are actual **read services** in CRUD methodology
- code reuse by using custom filters and join filters
- better find usages for methods because you are forced to implement only what you need

**Product.php**

```php
<?php

declare(strict_types=1);

namespace Acme\Domain\Product\Entity;

use Doctrine\ORM\Mapping as ORM;
use PrecisionSoft\Doctrine\Utility\Entity\CreatedTrait;
use PrecisionSoft\Doctrine\Utility\Entity\ModifiedTrait;
use PrecisionSoft\Doctrine\Utility\Repository\DoctrineRepository;

#[ORM\Entity(repositoryClass: DoctrineRepository::class)]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(options: ['collate' => 'utf8mb4_general_ci'])]
class Product
{
    use CreatedTrait;
    use ModifiedTrait;

    #[ORM\Id]
    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 64, nullable: false, unique: true)]
    private string $barcode;

    #[ORM\ManyToOne(targetEntity: ProductType::class, fetch: 'EXTRA_LAZY')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ProductType $productType;
}
```

**ProductRepository.php**

```php
<?php

declare(strict_types=1);

namespace Acme\Domain\Product\Repository;

use Acme\Domain\Product\Entity\Product;
use Acme\Domain\Product\Exception\Exception;
use Acme\Domain\Product\Exception\NotFoundException;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use PrecisionSoft\Doctrine\Utility\Join\JoinCollection;
use PrecisionSoft\Doctrine\Utility\Repository\AbstractRepository;

class ProductRepository extends AbstractRepository
{
    public const JOIN_PRODUCT_TYPE = 'joinProductType';

    protected function getEntityClass(): string
    {
        return Product::class;
    }

    public function find(int $productId): Product
    {
        /** @var Product|null $product */
        $product = $this->getDoctrineRepository()->find($productId);

        if (null === $product) {
            throw new NotFoundException('the product was not found');
        }

        return $product;
    }

    protected function attachCustomFilters(QueryBuilder $queryBuilder, array $filters): JoinCollection
    {
        $joins = new JoinCollection();

        foreach ($filters as $key => $value) {
            switch ($key) {
                case 'barcodeLike':
                    $baseKey = \substr($key, 0, -4);

                    $queryBuilder
                        ->andWhere(static::getAlias() . ".{$baseKey} LIKE :{$key}")
                        ->setParameter($key, $value);

                    break;
                case static::JOIN_PRODUCT_TYPE:
                    $joins->addJoin(
                        new Join(
                            $value,
                            static::getAlias() . '.productType',
                            ProductTypeRepository::getAlias(),
                        ),
                    );

                    break;
                default:
                    throw new Exception(\sprintf('invalid filter `%s` for `%s::%s`', $key, static::class, __FUNCTION__));
            }
        }

        return $joins;
    }
}
```

Values bind through the column's Doctrine type, single or in a list: `['uuid' => $uuid]`, `['uuid' => [$first, $second]]` and `['createdAt' => new DateTimeImmutable('2026-01-01')]` reach the driver converted, so a `Uuid` against a `BINARY(16)` column matches. `null` maps to `IS NULL`.

### Typed criteria

`createQueryBuilderFromCriteria()` is a typed alternative to the array filter API, for callers that build a query from validated input rather than from a hand-written array. Every field is checked against the mapping, so an unmapped name fails loudly instead of reaching DQL.

```php
use PrecisionSoft\Doctrine\Utility\Repository\Criteria\Criteria;
use PrecisionSoft\Doctrine\Utility\Repository\Criteria\Direction;
use PrecisionSoft\Doctrine\Utility\Repository\Criteria\Filter;
use PrecisionSoft\Doctrine\Utility\Repository\Criteria\Keyset;
use PrecisionSoft\Doctrine\Utility\Repository\Criteria\Operator;
use PrecisionSoft\Doctrine\Utility\Repository\Criteria\Sort;

$criteria = new Criteria(
    filters: [
        new Filter('status', Operator::In, ['active', 'pending']),
        new Filter('deletedAt', Operator::IsNull),
    ],
    sorts: [
        new Sort('label', Direction::Ascending),
        new Sort('id', Direction::Ascending),
    ],
    limit: 20,
);

$queryBuilder = $this->createQueryBuilderFromCriteria($criteria);
```

[`Operator`](./src/Repository/Criteria/Operator.php) covers `=`, `<>`, `>`, `>=`, `<`, `<=`, `IN`, `NOT IN`, `LIKE`, `IS NULL` and `IS NOT NULL`. Every value — a single comparison, an `IN` list, a keyset boundary — binds through the same conversion pipeline as the array filter API, so a `Uuid`, a `DateTime` or a Symfony uid is converted by the column's Doctrine type rather than reaching the driver as a string. An empty `IN` array follows [the empty array filter behavior](#empty-array-filter-behavior) below; an empty `NOT IN` excludes nothing and therefore adds no clause.

A `null` value is refused wherever it would be bound: `x = NULL` and `IN (..., NULL)` are unknown for every row and would match nothing without a word, and `NOT IN (..., NULL)` would exclude every row — say `Operator::IsNull` or `Operator::IsNotNull` instead. An owning side association takes every operator but `LIKE`, through its foreign key: `new Filter('category', Operator::In, $categoryIds)` and `new Sort('category')` are DQL, `LIKE` on a path that is not a state field is not, and is refused up front.

#### Keyset pagination

Passing a [`Keyset`](./src/Repository/Criteria/Keyset.php) turns the sort list into a page boundary. It needs one value per sort field, and it emulates a row value comparison, so mixed sort directions still produce a deterministic page:

```php
$nextPage = new Criteria(
    sorts: [new Sort('label'), new Sort('id')],
    keyset: new Keyset(['label' => $lastRow['label'], 'id' => $lastRow['id']]),
    limit: 20,
);
```

Sort on a combination that is unique overall — append the identifier, as above — or rows on a tie will repeat or vanish between pages. A `null` keyset value is rejected: comparing against `NULL` is never true and would silently truncate the page. For the same reason a keyset refuses to sort on a nullable column — a field mapped `nullable: true`, or a to-one association whose join column may be null: a row holding `NULL` is never reached by the comparison, and where the engines place it differs (PostgreSQL last on an ascending sort, MySQL and MariaDB first), so the same walk lost a row on one engine and threw on the other. DQL has no portable `NULLS FIRST` / `NULLS LAST`; sort on a column that never holds null, or coalesce the value into one. Without a keyset the sort is allowed as it always was.

### Empty array filter behavior

When `attachGenericFilters()` receives an empty array as a filter value (e.g. `['ids' => []]`), it cannot generate a valid `IN ()` clause. The default behavior is `EmptyArrayFilterBehavior::MatchNone`, which appends an always-false marker condition (`'<filterName>' = '<filterName>-emptyFilter'`) so the query returns zero rows and the offending filter is grep-able in query logs.

To turn empty array filters into hard errors, override `getFlags()`:

```php
use PrecisionSoft\Doctrine\Utility\Repository\AbstractRepository;
use PrecisionSoft\Doctrine\Utility\Repository\EmptyArrayFilterBehavior;

class ProductRepository extends AbstractRepository
{
    protected function getFlags(): array
    {
        return [
            EmptyArrayFilterBehavior::class => EmptyArrayFilterBehavior::ThrowException,
        ];
    }
}
```

`getFlags()` is the generic configuration hook for repository behavior — every flag is an enum keyed by its class, so future flags only require a new enum (no new method on `AbstractRepository`).

### Logger

Repositories can expose a `Psr\Log\LoggerInterface` so the abstract repository can warn on observable but non-fatal conditions (e.g. an empty array filter falling back to `MatchNone`):

```php
use Psr\Log\LoggerInterface;

class ProductRepository extends AbstractRepository
{
    public function __construct(private LoggerInterface $logger) {}

    protected function getLogger(): ?LoggerInterface
    {
        return $this->logger;
    }
}
```

By default `getLogger()` returns `null` and no logging happens. When provided, warnings include `repository`, `filter`, and `hint` context fields for filtering and remediation.

## DQL Functions

This library provides DQL functions for the MySQL family — MySQL and MariaDB alike, since `AbstractJsonSearch`
checks `AbstractMySQLPlatform` rather than `MySQLPlatform`. Register them in your Doctrine configuration:

```yaml
# config/packages/doctrine.yaml
doctrine:
    orm:
        dql:
            string_functions:
                JSON_CONTAINS: PrecisionSoft\Doctrine\Utility\Function\JsonContains
                JSON_CONTAINS_PATH: PrecisionSoft\Doctrine\Utility\Function\JsonContainsPath
                JSON_EXTRACT: PrecisionSoft\Doctrine\Utility\Function\JsonExtract
                JSON_SEARCH: PrecisionSoft\Doctrine\Utility\Function\JsonSearch
                JSON_UNQUOTE: PrecisionSoft\Doctrine\Utility\Function\JsonUnquote
                DATE_FORMAT: PrecisionSoft\Doctrine\Utility\Function\DateFormat
```

Available functions:

| Function             | DQL Usage                                                     | Description                                                     |
|----------------------|---------------------------------------------------------------|-----------------------------------------------------------------|
| `JSON_CONTAINS`      | `JSON_CONTAINS(field, value [, path])`                        | Test whether a JSON document contains a specific value          |
| `JSON_CONTAINS_PATH` | `JSON_CONTAINS_PATH(field, 'one'/'all', path [, ...])`        | Test whether a JSON document contains data at one or more paths |
| `JSON_EXTRACT`       | `JSON_EXTRACT(field, path [, ...])`                           | Extract data from a JSON document                               |
| `JSON_SEARCH`        | `JSON_SEARCH(field, 'one'/'all', search [, escape, path...])` | Search for a string in a JSON document                          |
| `JSON_UNQUOTE`       | `JSON_UNQUOTE(value)`                                         | Unquote a JSON value                                            |
| `DATE_FORMAT`        | `DATE_FORMAT(date, format)`                                   | Format a date                                                   |

## Lock services

[`LockServiceInterface`](./src/Contract/LockServiceInterface.php) is the portable named-lock contract, implemented by [`MysqlLockService`](./src/Service/MysqlLockService.php) and [`PostgresqlLockService`](./src/Service/PostgresqlLockService.php) on the shared [`AbstractLockService`](./src/Service/AbstractLockService.php) base. Depend on the interface and let the container decide the engine.

Both refuse a negative `timeout` before any query: MySQL would wait forever on `GET_LOCK(name, -1)`, MariaDB answers `NULL`, and PostgreSQL would compute a deadline in the past.

Two implementations are registered, so autowiring the interface is ambiguous and Symfony will refuse to compile the container. Name the one you want:

```yaml
# config/services.yaml
services:
    PrecisionSoft\Doctrine\Utility\Contract\LockServiceInterface:
        alias: PrecisionSoft\Doctrine\Utility\Service\PostgresqlLockService
```

### MysqlLockService

MySQL named locks via `GET_LOCK()` / `RELEASE_LOCK()`.

```php
use PrecisionSoft\Doctrine\Utility\Contract\LockServiceInterface;

public function __construct(private LockServiceInterface $lockService) {}

$lockService->acquire('my-lock', timeout: 5);

$hasLock = $lockService->hasLock('my-lock');

$lockService->release('my-lock');

$lockService->acquireLocks(['lock-a', 'lock-b'], timeout: 5);

$lockService->releaseLocks(['lock-a', 'lock-b']);
$lockService->releaseLocks();
```

Lock names longer than 64 characters are automatically hashed to fit MySQL's limit. Locks are reference-counted: calling `acquire()` multiple times with the same name increments a counter, and `release()` decrements it, only actually releasing the MySQL lock when the count reaches zero.

The counted re-acquire never asks the engine. `acquire($name, forceRefresh: true)` does: it asks whether *this* session still owns the lock, re-takes it when it does not, and adds no reference either way — so a `release()` per `acquire()` without `forceRefresh` still balances. Use it when the connection may have been closed or reset while the lock was held: named locks live exactly as long as their session, and after a reconnect the reference count keeps saying held while the new session holds nothing.

All errors throw [`MysqlLockException`](./src/Exception/MysqlLockException.php).

### PostgresqlLockService

The same contract on PostgreSQL session-level advisory locks. The lock name is hashed to a deterministic `(classid, objid)` pair, so any name of any length is usable without a server-side limit.

```php
use PrecisionSoft\Doctrine\Utility\Service\PostgresqlLockService;

$lockService = new PostgresqlLockService($managerRegistry);

$lockService->acquire('my-lock', timeout: 5);

$lockService->release('my-lock');
```

PostgreSQL has no blocking `pg_advisory_lock` variant that takes a timeout, so `timeout` is honoured by polling `pg_try_advisory_lock()` every 100 ms until the deadline passes; whole seconds only, and the wait may overrun by one poll.

The connection must run on a PostgreSQL platform, and all errors throw [`PostgresqlLockException`](./src/Exception/PostgresqlLockException.php). Both exceptions extend [`LockException`](./src/Exception/LockException.php), so a consumer written against the interface can catch one type.

### Who holds the lock

`hasLock()` answers whether *any* session on the server holds the lock — that is what `IS_FREE_LOCK()` reports on MySQL and what `pg_locks` reports on PostgreSQL. It cannot tell "held by me" from "held by someone else", which is the wrong question for a caller deciding whether to do the work:

```php
if (true === $lockService->hasLockInCurrentSession('my-lock')) {
    /* this connection owns it, so the critical section is ours */
}
```

`hasLockInCurrentSession()` compares the owner against this very connection — `IS_USED_LOCK()` against `CONNECTION_ID()` on MySQL, `pg_locks.pid` against `pg_backend_pid()` on PostgreSQL.

### Releasing and retrying

`release()` drops its bookkeeping only when the engine answered: either it released the lock, or it reported the lock was never established by this session. When the call itself fails — a dropped connection, an unreachable server — the reference count is kept, so a later `release()` retries against the engine instead of leaving a lock held on the server with nothing tracking it. Pass `throwException: true` to see that failure; the default swallows it.

`releaseLocks()` with no arguments drains every held lock — of every entity manager, whatever `$entityManagerName` says — and stops on the first lock whose release did not reach the engine rather than retrying it in a loop; with `throwException: true` that first failure is thrown and the locks after it stay held and bookkept.

## MySqlWalker (USE/FORCE/IGNORE INDEX)

A custom SQL walker for controlling MySQL index hints in DQL queries.

```php
use Doctrine\ORM\Query;
use PrecisionSoft\Doctrine\Utility\Walker\MySqlWalker;

$query = $entityManager->createQuery('...');
$query->setHint(Query::HINT_CUSTOM_OUTPUT_WALKER, MySqlWalker::class);

$query->setHint(MySqlWalker::HINT_USE_INDEX, 'my_index');
$query->setHint(MySqlWalker::HINT_FORCE_INDEX, 'PRIMARY');
$query->setHint(MySqlWalker::HINT_IGNORE_INDEX, 'PRIMARY, other_index');
$query->setHint(MySqlWalker::HINT_IGNORE_INDEX_ON_JOIN, ['my_index', 'joined_table']);
$query->setHint(MySqlWalker::HINT_SELECT_FOR_UPDATE, true);
```

Index names are validated against `[a-zA-Z_][a-zA-Z0-9_]*` pattern for safety.

## Entity Traits

### CreatedTrait

Adds a `created` column (`DATETIME`, defaults to `CURRENT_TIMESTAMP`) with getter/setter.

```php
use PrecisionSoft\Doctrine\Utility\Entity\CreatedTrait;

class MyEntity
{
    use CreatedTrait;
}
```

### ModifiedTrait

Adds a `modified` column (`DATETIME`, defaults to `CURRENT_TIMESTAMP`) with getter/setter and an automatic `#[ORM\PreUpdate]` callback.

**Important:** The consuming entity must have the `#[ORM\HasLifecycleCallbacks]` attribute for the automatic update to work.

```php
use Doctrine\ORM\Mapping as ORM;
use PrecisionSoft\Doctrine\Utility\Entity\ModifiedTrait;

#[ORM\HasLifecycleCallbacks]
class MyEntity
{
    use ModifiedTrait;
}
```

## Exception context

Every exception in this package carries a structured `context` array next to its message, so the facts describing a failure do not have to be parsed back out of a string:

```php
try {
    // ...
} catch (Exception $exception) {
    $logger->error($exception->getMessage(), $exception->getContext());
}
```

`getContext()` returns `[]` when nothing was attached. `setContext()` replaces it and returns the exception, and the constructor accepts it as an optional fourth argument. Values are expected to be scalars, so the array stays serialisable by a logger.

The context is purely **additive**: no message, code or previous throwable changed when it was introduced, so code that logs only `getMessage()` behaves exactly as before.

What this package attaches: `MysqlLockService::releaseLocks()` reports `lockName`, `entityManagerName` and
`releasedAll` when a lock cannot be released. That matters because the message it rethrows is the inner throwable's and names no lock, so before the context there was no way to tell *which* lock failed; `releasedAll` distinguishes the release-all loop from the named-list one.

Every exception in the package implements `Contract\ExceptionInterface`, so a consumer can read the context off any of them without knowing the concrete class. A subclass of your own that already declares a `$context` property or a
`getContext()`/`setContext()` method will collide with `Exception\Trait\ExceptionTrait`.

## Example application

A runnable product catalogue lives under [`.example/`](./.example/README.md): categories, products and currencies as `DoctrineRepository` entities, a `ProductRepository` on `AbstractRepository` with generic, custom and join filters, typed criteria pages walked by keyset, the six DQL functions over a json attributes column, the `MySqlWalker` index hints proved by `EXPLAIN`, and a repricing service that holds a named lock on whichever engine the connection speaks — a second session is refused while the first holds the product — with `CreatedTrait` and `ModifiedTrait` stamping the rows. It runs on MySQL, MariaDB and PostgreSQL, installs the package from the working tree through a path repository, so it always tests the code as it stands; run it with `.dev/validate/all.sh --example` (which starts the databases) or `cd .example && composer install && composer check`. The directory is `export-ignore`d and never reaches a consumer's `vendor/`.

## Dev

```shell
git clone git@github.com:precision-soft/doctrine-utility.git
cd doctrine-utility
./dc build && ./dc up -d
```

Run the full gate the way the pre-commit hook runs it - the CI workflow in
`.github/workflows/ci.yml` calls the same composer scripts, so the two cannot drift:

```shell
.dev/validate/all.sh
.dev/validate/all.sh --audit    # also audits the locked dependencies ( needs the network )
.dev/validate/all.sh --staged   # what the pre-commit hook runs: nothing unless the index carries php
```

Mutation testing is opt-in for the same reason, plus cost - it runs the suite once per mutant:

```shell
.dev/validate/all.sh --mutation
```

Infection is a pinned phar in the image, not a composer dependency, and `infection.json5` carries a
`minMsi` floor equal to the last measured score, so the section fails when a change makes the suite weaker rather than only reporting a number. Raise the floor when the score improves.

The integration suite needs real databases, which are behind a Compose profile so the default `up`
stays fast and offline:

```shell
./dc --profile db up -d
.dev/validate/all.sh --integration
```

Tests connect through `DATABASE_URL_MYSQL`, `DATABASE_URL_MARIADB` and `DATABASE_URL_POSTGRESQL` and skip themselves when those services are not running, so `composer check` never depends on them.

The [example application](#example-application) has its own section, which starts the same databases, installs `.example/` from the working tree and runs its `composer check`:

```shell
.dev/validate/all.sh --example
```

Build against another PHP version with the `PHP_VERSION` build argument - each version is tagged as its own image, so switching back and forth costs nothing:

```shell
PHP_VERSION=8.4 ./dc build && PHP_VERSION=8.4 ./dc up -d
```

Coverage is available through pcov, which is installed but disabled by default:

```shell
./dc exec dev php -d pcov.enabled=1 vendor/bin/simple-phpunit --coverage-text
```

After editing a file, `./dc restart dev` (a few seconds) is enough to be sure the container is not serving a stale copy - the bind mount can keep the old inode after an atomic rewrite.
