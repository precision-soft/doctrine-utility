<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Example\Test\Functional;

use PHPUnit\Framework\Attributes\DataProviderExternal;
use PrecisionSoft\Doctrine\Utility\Example\Test\Utility\CatalogDatabase;
use PrecisionSoft\Doctrine\Utility\Example\Test\Utility\CatalogTestCase;

/**
 * The six DQL functions over the product attributes, on the MySQL family they are written for.
 *
 * @internal
 */
final class JsonFunctionTest extends CatalogTestCase
{
    #[DataProviderExternal(CatalogDatabase::class, 'dataProviderMySqlEngine')]
    public function testJsonContainsFindsATagInsideTheDocument(string $environmentVariable): void
    {
        $repository = $this->bootRepository($environmentVariable);

        static::assertSame(['apple', 'banana'], $repository->findNamesWithTag('fresh'));
        static::assertSame(['milk', 'cheese'], $repository->findNamesWithTag('cold'));
        static::assertSame([], $repository->findNamesWithTag('frozen'));
    }

    #[DataProviderExternal(CatalogDatabase::class, 'dataProviderMySqlEngine')]
    public function testJsonExtractUnquotedComparesAScalarAttribute(string $environmentVariable): void
    {
        $repository = $this->bootRepository($environmentVariable);

        static::assertSame(['cheese'], $repository->findNamesByAttribute('$.origin', 'FR'));
        static::assertSame(['apple'], $repository->findNamesByAttribute('$.origin', 'RO'));
    }

    #[DataProviderExternal(CatalogDatabase::class, 'dataProviderMySqlEngine')]
    public function testJsonContainsPathTellsWhichDocumentsCarryAnAttribute(string $environmentVariable): void
    {
        $repository = $this->bootRepository($environmentVariable);

        static::assertSame(['apple', 'cheese'], $repository->findNamesWithAttributePath('$.origin'));
        static::assertSame(['apple', 'banana', 'milk', 'cheese'], $repository->findNamesWithAttributePath('$.tags'));
    }

    #[DataProviderExternal(CatalogDatabase::class, 'dataProviderMySqlEngine')]
    public function testJsonSearchReportsWhereATagSits(string $environmentVariable): void
    {
        $repository = $this->bootRepository($environmentVariable);

        static::assertSame(['cheese' => '$.tags[1]'], $repository->findTagPaths('aged'));
        static::assertSame(['apple' => '$.tags[0]', 'banana' => '$.tags[0]'], $repository->findTagPaths('fresh'));
    }

    #[DataProviderExternal(CatalogDatabase::class, 'dataProviderMySqlEngine')]
    public function testDateFormatGroupsByTheMonthOfCreation(string $environmentVariable): void
    {
        $repository = $this->bootRepository($environmentVariable);

        $counts = $repository->countByCreatedMonth();

        static::assertCount(1, $counts, 'the seed is created in one go');
        static::assertSame(5, \reset($counts));
        static::assertMatchesRegularExpression('/^\d{4}-\d{2}$/', (string)\key($counts));
    }
}
