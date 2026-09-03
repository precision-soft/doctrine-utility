<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Example\Test\Utility;

use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use PrecisionSoft\Doctrine\Utility\Example\Entity\Category;
use PrecisionSoft\Doctrine\Utility\Example\Entity\Currency;
use PrecisionSoft\Doctrine\Utility\Example\Entity\Product;
use Symfony\Component\Uid\Uuid;

/**
 * Five products in two categories and two currencies, with fixed identities so a keyset boundary is a known value.
 *
 * @internal
 */
final class CatalogSeed
{
    public const IDENTITY_APPLE = '01111111-1111-4111-8111-111111111111';
    public const IDENTITY_BANANA = '02222222-2222-4222-8222-222222222222';
    public const IDENTITY_MILK = '03333333-3333-4333-8333-333333333333';
    public const IDENTITY_CHEESE = '04444444-4444-4444-8444-444444444444';
    public const IDENTITY_YOGURT = '05555555-5555-4555-8555-555555555555';

    public const BARCODE_APPLE = '1000000000017';

    /** @var array<string, Product> */
    private array $products = [];

    /** @var array<string, Category> */
    private array $categories = [];

    /** @var array<string, Currency> */
    private array $currencies = [];

    public static function plant(EntityManagerInterface $entityManager): self
    {
        $seed = new self();

        $seed->currencies = ['EUR' => new Currency('EUR', 'euro'), 'RON' => new Currency('RON', 'leu')];
        $seed->categories = ['fruit' => new Category('fruit', 1), 'dairy' => new Category('dairy', 2)];

        $seed->products = [
            'apple' => (new Product(Uuid::fromString(static::IDENTITY_APPLE), 'apple', static::BARCODE_APPLE, 120, $seed->currencies['EUR'], $seed->categories['fruit']))
                ->setAttributes(['tags' => ['fresh', 'red'], 'origin' => 'RO']),
            'banana' => (new Product(Uuid::fromString(static::IDENTITY_BANANA), 'banana', '1000000000024', 90, $seed->currencies['EUR'], $seed->categories['fruit']))
                ->setAttributes(['tags' => ['fresh', 'yellow']]),
            'milk' => (new Product(Uuid::fromString(static::IDENTITY_MILK), 'milk', '1000000000031', 550, $seed->currencies['RON'], $seed->categories['dairy']))
                ->setAttributes(['tags' => ['cold']]),
            'cheese' => (new Product(Uuid::fromString(static::IDENTITY_CHEESE), 'cheese', '1000000000048', 1490, $seed->currencies['RON'], $seed->categories['dairy']))
                ->setAttributes(['tags' => ['cold', 'aged'], 'origin' => 'FR']),
            'yogurt' => (new Product(Uuid::fromString(static::IDENTITY_YOGURT), 'yogurt', '1000000000055', 300, $seed->currencies['EUR'], $seed->categories['dairy']))
                ->setDiscontinuedAt(new DateTime('2026-06-30 00:00:00')),
        ];

        foreach ([...$seed->currencies, ...$seed->categories, ...$seed->products] as $entity) {
            $entityManager->persist($entity);
        }

        $entityManager->flush();
        $entityManager->clear();

        return $seed;
    }

    public function getProductId(string $name): int
    {
        return (int)$this->products[$name]->getId();
    }

    public function getCategoryId(string $name): int
    {
        return (int)$this->categories[$name]->getId();
    }

    public function getCurrencyId(string $code): int
    {
        return (int)$this->currencies[$code]->getId();
    }
}
