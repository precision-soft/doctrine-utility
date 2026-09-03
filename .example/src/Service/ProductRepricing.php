<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Example\Service;

use Doctrine\ORM\EntityManagerInterface;
use PrecisionSoft\Doctrine\Utility\Contract\LockServiceInterface;
use PrecisionSoft\Doctrine\Utility\Example\Entity\Product;
use PrecisionSoft\Doctrine\Utility\Exception\LockException;

/**
 * A price change is a critical section: two operators repricing the same product must serialise, whatever engine holds the lock.
 */
class ProductRepricing
{
    public function __construct(
        protected LockServiceInterface $lockService,
        protected EntityManagerInterface $entityManager,
    ) {}

    public static function getLockName(Product $product): string
    {
        return 'product-' . $product->getIdentity()->toRfc4122();
    }

    /**
     * @throws LockException if another session holds the product within `$timeout` seconds
     */
    public function reprice(Product $product, int $price, int $timeout = 0): void
    {
        $this->withProductLocked($product, function () use ($product, $price): void {
            $product->setPrice($price);
            $this->entityManager->flush();
        }, $timeout);
    }

    /**
     * Every product is locked before the first price moves, in one sorted acquisition so two operators cannot deadlock.
     *
     * @param list<Product> $products
     *
     * @throws LockException if any of the products is held by another session
     */
    public function repriceMany(array $products, int $price, int $timeout = 0): void
    {
        $lockNames = \array_map(static fn(Product $product): string => static::getLockName($product), $products);

        $this->lockService->acquireLocks($lockNames, $timeout);

        try {
            foreach ($products as $product) {
                $product->setPrice($price);
            }

            $this->entityManager->flush();
        } finally {
            $this->lockService->releaseLocks($lockNames, throwException: true);
        }
    }

    /**
     * @throws LockException if the product is held by another session
     */
    public function withProductLocked(Product $product, callable $operation, int $timeout = 0): void
    {
        $lockName = static::getLockName($product);

        $this->lockService->acquire($lockName, $timeout);

        try {
            $operation();
        } finally {
            $this->lockService->release($lockName, throwException: true);
        }
    }

    public function holdsProduct(Product $product): bool
    {
        return $this->lockService->hasLockInCurrentSession(static::getLockName($product));
    }
}
