<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Example\Test\Utility;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\AbstractManagerRegistry;
use Doctrine\Persistence\Proxy;
use PrecisionSoft\Doctrine\Utility\Example\Exception\Exception;

/**
 * What the framework's registry is in an application: one manager, one connection, both named `default`.
 *
 * @internal
 */
final class CatalogManagerRegistry extends AbstractManagerRegistry
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
        parent::__construct(
            'catalog',
            ['default' => 'connection'],
            ['default' => 'manager'],
            'default',
            'default',
            Proxy::class,
        );
    }

    protected function getService(string $name): object
    {
        return match ($name) {
            'manager' => $this->entityManager,
            'connection' => $this->entityManager->getConnection(),
            default => throw new Exception(\sprintf('unknown service `%s`', $name)),
        };
    }

    protected function resetService(string $name): void {}
}
