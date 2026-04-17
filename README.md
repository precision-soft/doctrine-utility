# Doctrine Utility

[![PHP >= 8.2](https://img.shields.io/badge/php-%3E%3D8.2-8892BF)](https://www.php.net/)
[![PHPStan Level 8](https://img.shields.io/badge/phpstan-level%208-brightgreen)](https://phpstan.org/)
[![Code Style PER-CS2.0](https://img.shields.io/badge/code%20style-PER--CS2.0-blue)](https://www.php-fig.org/per/coding-style/)
[![License MIT](https://img.shields.io/badge/license-MIT-green)](LICENSE)

Doctrine custom types, functions, and services for **MySQL**.

**You may fork and modify it as you wish.**

Any suggestions are welcomed.

## Requirements

- PHP 8.2+
- Doctrine ORM 3
- Doctrine DBAL 4
- MySQL (all features — DQL functions, MySqlWalker, and MysqlLockService — require MySQL)

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

This library provides MySQL-specific DQL functions. Register them in your Doctrine configuration:

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

## MysqlLockService

A service for MySQL named locks (advisory locks) via `GET_LOCK()` / `RELEASE_LOCK()`.

```php
use PrecisionSoft\Doctrine\Utility\Service\MysqlLockService;

public function __construct(private MysqlLockService $lockService) {}

$lockService->acquire('my-lock', timeout: 5);

$hasLock = $lockService->hasLock('my-lock');

$lockService->release('my-lock');

$lockService->acquireLocks(['lock-a', 'lock-b'], timeout: 5);

$lockService->releaseLocks(['lock-a', 'lock-b']);
$lockService->releaseLocks();
```

Lock names longer than 64 characters are automatically hashed to fit MySQL's limit. Locks are reference-counted: calling `acquire()` multiple times with the same name increments a counter, and `release()` decrements it, only actually releasing the MySQL lock when the count reaches zero.

All errors throw `MysqlLockException`.

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

## Dev

```shell
git clone git@github.com:precision-soft/doctrine-utility.git
cd doctrine-utility
./dc build && ./dc up -d
```
