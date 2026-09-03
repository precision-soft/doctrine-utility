<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Example\Test\Functional;

use PHPUnit\Framework\Attributes\DataProviderExternal;
use PrecisionSoft\Doctrine\Utility\Example\Entity\Product;
use PrecisionSoft\Doctrine\Utility\Example\Repository\ProductRepository;
use PrecisionSoft\Doctrine\Utility\Example\Test\Utility\CatalogDatabase;
use PrecisionSoft\Doctrine\Utility\Example\Test\Utility\CatalogSeed;
use PrecisionSoft\Doctrine\Utility\Example\Test\Utility\CatalogTestCase;
use PrecisionSoft\Doctrine\Utility\Exception\Exception;
use PrecisionSoft\Doctrine\Utility\Repository\Criteria\Criteria;
use PrecisionSoft\Doctrine\Utility\Repository\Criteria\Direction;
use PrecisionSoft\Doctrine\Utility\Repository\Criteria\Filter;
use PrecisionSoft\Doctrine\Utility\Repository\Criteria\Keyset;
use PrecisionSoft\Doctrine\Utility\Repository\Criteria\Operator;
use PrecisionSoft\Doctrine\Utility\Repository\Criteria\Sort;
use Symfony\Component\Uid\Uuid;

/**
 * The typed criteria API: validated against the mapping, every operator, and keyset pages walked to the end.
 *
 * @internal
 */
final class TypedCriteriaTest extends CatalogTestCase
{
    #[DataProviderExternal(CatalogDatabase::class, 'dataProviderEveryEngine')]
    public function testEveryOperatorSelectsWhatItSays(string $environmentVariable): void
    {
        $repository = $this->bootRepository($environmentVariable);
        $byId = [new Sort('id', Direction::Ascending)];

        static::assertSame(['yogurt'], $this->find($repository, [new Filter('price', Operator::Equal, 300)], $byId));
        static::assertSame(['apple', 'banana', 'milk', 'cheese'], $this->find($repository, [new Filter('price', Operator::NotEqual, 300)], $byId));
        static::assertSame(['milk', 'cheese'], $this->find($repository, [new Filter('price', Operator::GreaterThan, 300)], $byId));
        static::assertSame(['milk', 'cheese', 'yogurt'], $this->find($repository, [new Filter('price', Operator::GreaterThanOrEqual, 300)], $byId));
        static::assertSame(['banana'], $this->find($repository, [new Filter('price', Operator::LessThan, 120)], $byId));
        static::assertSame(['apple', 'banana'], $this->find($repository, [new Filter('price', Operator::LessThanOrEqual, 120)], $byId));
        static::assertSame(['apple', 'cheese'], $this->find($repository, [new Filter('name', Operator::In, ['apple', 'cheese'])], $byId));
        static::assertSame(['milk', 'yogurt'], $this->find($repository, [new Filter('name', Operator::NotIn, ['apple', 'banana', 'cheese'])], $byId));
        static::assertSame(['banana'], $this->find($repository, [new Filter('name', Operator::Like, '%an%')], $byId));
        static::assertSame(['yogurt'], $this->find($repository, [new Filter('discontinuedAt', Operator::IsNotNull)], $byId));
        static::assertSame(['apple', 'banana', 'milk', 'cheese'], $this->find($repository, [new Filter('discontinuedAt', Operator::IsNull)], $byId));
    }

    #[DataProviderExternal(CatalogDatabase::class, 'dataProviderEveryEngine')]
    public function testAUidAndAnAssociationAreComparedByWhatTheColumnHolds(string $environmentVariable): void
    {
        $repository = $this->bootRepository($environmentVariable);
        $byId = [new Sort('id', Direction::Ascending)];

        static::assertSame(
            ['cheese'],
            $this->find($repository, [new Filter('identity', Operator::Equal, Uuid::fromString(CatalogSeed::IDENTITY_CHEESE))], $byId),
        );
        static::assertSame(
            ['milk', 'cheese', 'yogurt'],
            $this->find($repository, [new Filter('category', Operator::Equal, $this->getSeed()->getCategoryId('dairy'))], $byId),
        );
        static::assertSame(
            ['apple', 'banana'],
            $this->find($repository, [new Filter('category', Operator::In, [$this->getSeed()->getCategoryId('fruit')])], $byId),
        );
    }

    #[DataProviderExternal(CatalogDatabase::class, 'dataProviderEveryEngine')]
    public function testAKeysetWalksTheCatalogueByPriceToTheEnd(string $environmentVariable): void
    {
        $repository = $this->bootRepository($environmentVariable);
        $sorts = [new Sort('price', Direction::Ascending), new Sort('id', Direction::Ascending)];
        $pages = [];
        $keyset = null;

        do {
            $page = $repository->findPage(new Criteria(sorts: $sorts, keyset: $keyset, limit: 2));
            $pages[] = static::names($page);

            if ([] !== $page) {
                $last = $page[\count($page) - 1];
                $keyset = new Keyset(['price' => $last->getPrice(), 'id' => $last->getId()]);
            }
        } while ([] !== $page);

        static::assertSame([['banana', 'apple'], ['yogurt', 'milk'], ['cheese'], []], $pages);
    }

    #[DataProviderExternal(CatalogDatabase::class, 'dataProviderEveryEngine')]
    public function testAKeysetRefusesANullableSortColumn(string $environmentVariable): void
    {
        $repository = $this->bootRepository($environmentVariable);

        /* `yogurt` is the only discontinued product: a keyset over that column would lose or repeat the other four */
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('keyset sort field `discontinuedAt` is nullable');

        $repository->findPage(new Criteria(
            sorts: [new Sort('discontinuedAt'), new Sort('id')],
            keyset: new Keyset(['discontinuedAt' => '2026-01-01', 'id' => 0]),
        ));
    }

    #[DataProviderExternal(CatalogDatabase::class, 'dataProviderEveryEngine')]
    public function testANullValueMustBeSaidWithTheNullOperator(string $environmentVariable): void
    {
        $repository = $this->bootRepository($environmentVariable);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('criteria operator `=` does not accept null');

        $repository->findPage(new Criteria(filters: [new Filter('discontinuedAt', Operator::Equal, null)]));
    }

    #[DataProviderExternal(CatalogDatabase::class, 'dataProviderEveryEngine')]
    public function testLikeCannotApplyToAnAssociation(string $environmentVariable): void
    {
        $repository = $this->bootRepository($environmentVariable);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('criteria operator `LIKE` cannot apply to the association `category`');

        $repository->findPage(new Criteria(filters: [new Filter('category', Operator::Like, '%dairy%')]));
    }

    /**
     * @param list<Filter> $filters
     * @param list<Sort> $sorts
     *
     * @return list<string>
     */
    private function find(ProductRepository $repository, array $filters, array $sorts): array
    {
        return \array_map(
            static fn(Product $product): string => $product->getName(),
            $repository->findPage(new Criteria(filters: $filters, sorts: $sorts)),
        );
    }
}
