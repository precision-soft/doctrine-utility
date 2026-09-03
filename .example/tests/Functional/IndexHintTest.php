<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Example\Test\Functional;

use PHPUnit\Framework\Attributes\DataProviderExternal;
use PrecisionSoft\Doctrine\Utility\Example\Repository\ProductRepository;
use PrecisionSoft\Doctrine\Utility\Example\Test\Utility\CatalogDatabase;
use PrecisionSoft\Doctrine\Utility\Example\Test\Utility\CatalogSeed;
use PrecisionSoft\Doctrine\Utility\Example\Test\Utility\CatalogTestCase;
use PrecisionSoft\Doctrine\Utility\Walker\MySqlWalker;

/**
 * `MySqlWalker` hints, proved by the plan the server chose, not by the SQL text.
 *
 * @internal
 */
final class IndexHintTest extends CatalogTestCase
{
    #[DataProviderExternal(CatalogDatabase::class, 'dataProviderMySqlEngine')]
    public function testForceIndexMakesTheServerUseTheBarcodeIndex(string $environmentVariable): void
    {
        $repository = $this->bootRepository($environmentVariable);

        $product = $repository->findByBarcode(CatalogSeed::BARCODE_APPLE, MySqlWalker::HINT_FORCE_INDEX);

        static::assertNotNull($product);
        static::assertSame('apple', $product->getName());
        static::assertSame(
            ProductRepository::BARCODE_INDEX,
            $repository->explainBarcodeLookup(CatalogSeed::BARCODE_APPLE, MySqlWalker::HINT_FORCE_INDEX)[0]['key'],
        );
    }

    #[DataProviderExternal(CatalogDatabase::class, 'dataProviderMySqlEngine')]
    public function testIgnoreIndexKeepsTheServerOffTheBarcodeIndex(string $environmentVariable): void
    {
        $repository = $this->bootRepository($environmentVariable);

        $product = $repository->findByBarcode(CatalogSeed::BARCODE_APPLE, MySqlWalker::HINT_IGNORE_INDEX);

        static::assertNotNull($product);
        static::assertSame('apple', $product->getName());
        static::assertNotSame(
            ProductRepository::BARCODE_INDEX,
            $repository->explainBarcodeLookup(CatalogSeed::BARCODE_APPLE, MySqlWalker::HINT_IGNORE_INDEX)[0]['key'],
        );
    }
}
