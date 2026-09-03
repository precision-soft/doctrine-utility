<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Example\Test\Functional;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PrecisionSoft\Doctrine\Utility\Contract\LockServiceInterface;
use PrecisionSoft\Doctrine\Utility\Example\Entity\Product;
use PrecisionSoft\Doctrine\Utility\Example\Service\LockServiceFactory;
use PrecisionSoft\Doctrine\Utility\Example\Service\ProductRepricing;
use PrecisionSoft\Doctrine\Utility\Example\Test\Utility\CatalogDatabase;
use PrecisionSoft\Doctrine\Utility\Example\Test\Utility\CatalogManagerRegistry;
use PrecisionSoft\Doctrine\Utility\Example\Test\Utility\CatalogTestCase;
use PrecisionSoft\Doctrine\Utility\Exception\LockException;

/**
 * Two operators, two sessions, one product: the named lock serialises them on every engine.
 *
 * @internal
 */
final class RepricingLockTest extends CatalogTestCase
{
    #[DataProviderExternal(CatalogDatabase::class, 'dataProviderEveryEngine')]
    public function testARepriceHoldsTheLockOnlyWhileItRunsAndStampsModified(string $environmentVariable): void
    {
        [$repricing, $lockService] = $this->bootRepricing($environmentVariable);
        $apple = $this->loadProduct($this->entityManagers[0], 'apple');
        $created = $apple->getCreated();
        $modifiedBefore = $apple->getModified();

        static::assertNotNull($created);
        static::assertNotNull($modifiedBefore);
        static::assertSame($created->format('Y-m-d H:i:s'), $modifiedBefore->format('Y-m-d H:i:s'), 'a new product is modified when it is created');

        $modifiedBefore = clone $modifiedBefore;

        \usleep(1_100_000);
        $repricing->reprice($apple, 130);

        static::assertSame(130, $apple->getPrice());
        static::assertFalse($lockService->hasLock(ProductRepricing::getLockName($apple)));
        static::assertGreaterThan($modifiedBefore, $apple->getModified(), 'ModifiedTrait stamps the update through the lifecycle callback');
    }

    #[DataProviderExternal(CatalogDatabase::class, 'dataProviderEveryEngine')]
    public function testASecondSessionCannotRepriceAProductWhileTheFirstHoldsIt(string $environmentVariable): void
    {
        [$firstRepricing] = $this->bootRepricing($environmentVariable);
        [$secondRepricing] = $this->bootRepricing($environmentVariable);
        $apple = $this->loadProduct($this->entityManagers[0], 'apple');
        $appleOfTheSecond = $this->loadProduct($this->entityManagers[1], 'apple');
        $refused = null;

        $firstRepricing->withProductLocked($apple, function () use ($firstRepricing, $secondRepricing, $apple, $appleOfTheSecond, &$refused): void {
            static::assertTrue($firstRepricing->holdsProduct($apple));
            static::assertFalse($secondRepricing->holdsProduct($appleOfTheSecond));

            try {
                $secondRepricing->reprice($appleOfTheSecond, 140);
            } catch (LockException $lockException) {
                $refused = $lockException;
            }
        });

        static::assertInstanceOf(LockException::class, $refused);
        static::assertStringContainsString('another operation with the same id is already in progress', $refused->getMessage());

        /* loading again clears the second session, so the product it repriced next is the one it just read */
        $appleOfTheSecond = $this->loadProduct($this->entityManagers[1], 'apple');
        static::assertSame(120, $appleOfTheSecond->getPrice(), 'the refused reprice changed nothing');

        $secondRepricing->reprice($appleOfTheSecond, 140);

        static::assertSame(140, $this->loadProduct($this->entityManagers[0], 'apple')->getPrice(), 'once released, the second session goes through');
    }

    #[DataProviderExternal(CatalogDatabase::class, 'dataProviderEveryEngine')]
    public function testRepricingManyLocksEveryProductFirstAndFreesThemAll(string $environmentVariable): void
    {
        [$repricing, $lockService] = $this->bootRepricing($environmentVariable);
        $entityManager = $this->entityManagers[0];
        $products = [$this->loadProduct($entityManager, 'milk'), $this->loadProduct($entityManager, 'cheese')];

        $repricing->repriceMany($products, 999);

        foreach ($products as $product) {
            static::assertSame(999, $product->getPrice());
            static::assertFalse($lockService->hasLock(ProductRepricing::getLockName($product)));
        }

        static::assertSame(120, $this->loadProduct($entityManager, 'apple')->getPrice(), 'the others are untouched');
    }

    /** @return array{ProductRepricing, LockServiceInterface} */
    private function bootRepricing(string $environmentVariable): array
    {
        $entityManager = $this->bootEntityManager($environmentVariable);
        $lockService = LockServiceFactory::create($entityManager, new CatalogManagerRegistry($entityManager));

        return [new ProductRepricing($lockService, $entityManager), $lockService];
    }

    private function loadProduct(EntityManagerInterface $entityManager, string $name): Product
    {
        $entityManager->clear();
        $product = $entityManager->getRepository(Product::class)->findOneBy(['name' => $name]);

        static::assertInstanceOf(Product::class, $product);

        return $product;
    }
}
