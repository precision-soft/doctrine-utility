<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Example\Test\Functional;

use PHPUnit\Framework\Attributes\DataProviderExternal;
use PrecisionSoft\Doctrine\Utility\Example\Exception\Exception;
use PrecisionSoft\Doctrine\Utility\Example\Repository\ProductRepository;
use PrecisionSoft\Doctrine\Utility\Example\Test\Utility\CatalogDatabase;
use PrecisionSoft\Doctrine\Utility\Example\Test\Utility\CatalogSeed;
use PrecisionSoft\Doctrine\Utility\Example\Test\Utility\CatalogTestCase;
use PrecisionSoft\Doctrine\Utility\Exception\Exception as LibraryException;
use PrecisionSoft\Doctrine\Utility\Repository\EmptyArrayFilterBehavior;
use Symfony\Component\Uid\Uuid;

/**
 * The array filter API: a mapped name is a generic filter, anything else reaches `attachCustomFilters()`.
 *
 * @internal
 */
final class ArrayFilterTest extends CatalogTestCase
{
    #[DataProviderExternal(CatalogDatabase::class, 'dataProviderEveryEngine')]
    public function testAMappedFieldOrAssociationIsAGenericFilter(string $environmentVariable): void
    {
        $repository = $this->bootRepository($environmentVariable);

        static::assertSame(
            ['apple', 'banana', 'yogurt'],
            static::names($repository->findByFilters(['currency' => $this->getSeed()->getCurrencyId('EUR')])),
        );
        static::assertSame(
            ['milk', 'cheese'],
            static::names($repository->findByFilters(['category' => $this->getSeed()->getCategoryId('dairy'), 'discontinuedAt' => null])),
        );
        static::assertSame(['yogurt'], static::names($repository->findByFilters(['price' => 300])));
    }

    #[DataProviderExternal(CatalogDatabase::class, 'dataProviderEveryEngine')]
    public function testAUidBindsThroughTheColumnTypeAloneAndInAList(string $environmentVariable): void
    {
        $repository = $this->bootRepository($environmentVariable);

        static::assertSame(
            ['apple'],
            static::names($repository->findByFilters(['identity' => Uuid::fromString(CatalogSeed::IDENTITY_APPLE)])),
        );
        static::assertSame(
            ['milk', 'cheese'],
            static::names($repository->findByFilters(['identity' => [
                Uuid::fromString(CatalogSeed::IDENTITY_MILK),
                Uuid::fromString(CatalogSeed::IDENTITY_CHEESE),
            ]])),
        );
    }

    #[DataProviderExternal(CatalogDatabase::class, 'dataProviderEveryEngine')]
    public function testAnEmptyListMatchesNothingUnlessTheRepositorySaysItIsAnError(string $environmentVariable): void
    {
        $repository = $this->bootRepository($environmentVariable);

        static::assertSame([], $repository->findByFilters(['category' => []]));

        $repository->setEmptyArrayFilterBehavior(EmptyArrayFilterBehavior::ThrowException);

        $this->expectException(LibraryException::class);

        $repository->findByFilters(['category' => []]);
    }

    #[DataProviderExternal(CatalogDatabase::class, 'dataProviderEveryEngine')]
    public function testCustomFiltersAndTheJoinAreDeclaredByTheRepository(string $environmentVariable): void
    {
        $repository = $this->bootRepository($environmentVariable);

        static::assertSame(['banana'], static::names($repository->findByFilters([ProductRepository::FILTER_NAME_LIKE => '%an%'])));

        $dairy = $repository->findByFilters([
            ProductRepository::JOIN_CATEGORY => ProductRepository::JOIN_INNER,
            ProductRepository::FILTER_CATEGORY_NAME => 'dairy',
        ], selectJoins: true);

        static::assertSame(['milk', 'cheese', 'yogurt'], static::names($dairy));
        static::assertSame('dairy', $dairy[0]->getCategory()->getName());
    }

    #[DataProviderExternal(CatalogDatabase::class, 'dataProviderEveryEngine')]
    public function testAFilterTheRepositoryDoesNotDeclareIsAnError(string $environmentVariable): void
    {
        $repository = $this->bootRepository($environmentVariable);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('invalid filter `colour`');

        $repository->findByFilters(['colour' => 'red']);
    }

    #[DataProviderExternal(CatalogDatabase::class, 'dataProviderEveryEngine')]
    public function testTheCategoryFilterNeedsItsJoin(string $environmentVariable): void
    {
        $repository = $this->bootRepository($environmentVariable);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('filter `categoryName` needs the `joinCategory` join');

        $repository->findByFilters([ProductRepository::FILTER_CATEGORY_NAME => 'dairy']);
    }
}
