<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Example\Test\Utility;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use PrecisionSoft\Doctrine\Utility\Example\Entity\Product;
use PrecisionSoft\Doctrine\Utility\Example\Repository\ProductRepository;

/**
 * One schema per test, dropped afterwards: the example shares the `test` database with the library's own suites.
 *
 * @internal
 */
abstract class CatalogTestCase extends TestCase
{
    /** @var list<EntityManagerInterface> */
    protected array $entityManagers = [];

    protected ?CatalogSeed $seed = null;

    /**
     * @param list<Product> $products
     *
     * @return list<string>
     */
    protected static function names(array $products): array
    {
        return \array_map(static fn(Product $product): string => $product->getName(), $products);
    }

    protected function tearDown(): void
    {
        foreach ($this->entityManagers as $index => $entityManager) {
            if (0 === $index) {
                CatalogDatabase::dropSchema($entityManager);
            }

            $entityManager->getConnection()->close();
        }

        $this->entityManagers = [];
        $this->seed = null;

        parent::tearDown();
    }

    /**
     * The first call creates the schema and plants the seed; a later one is a second session on the same catalogue.
     */
    protected function bootEntityManager(string $environmentVariable): EntityManagerInterface
    {
        try {
            $connection = CatalogDatabase::connect($environmentVariable);
        } catch (SkipException $skipException) {
            static::markTestSkipped($skipException->getMessage());
        }

        $entityManager = CatalogDatabase::createEntityManager($connection);

        if ([] === $this->entityManagers) {
            CatalogDatabase::createSchema($entityManager);
            $this->seed = CatalogSeed::plant($entityManager);
        }

        $this->entityManagers[] = $entityManager;

        return $entityManager;
    }

    protected function bootRepository(string $environmentVariable): ProductRepository
    {
        return (new ProductRepository())
            ->setManagerRegistry(new CatalogManagerRegistry($this->bootEntityManager($environmentVariable)));
    }

    protected function getSeed(): CatalogSeed
    {
        static::assertNotNull($this->seed, 'boot an entity manager first');

        return $this->seed;
    }
}
