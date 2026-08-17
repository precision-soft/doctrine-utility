<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Test\Utility;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\AbstractManagerRegistry;
use Doctrine\Persistence\Proxy;
use RuntimeException;

/**
 * Deliberately not a Mockery double: the registry is the boundary this suite exists to cross.
 *
 * @internal
 */
final class IntegrationManagerRegistry extends AbstractManagerRegistry
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
        parent::__construct(
            'integration',
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
            default => throw new RuntimeException(\sprintf('unknown service `%s`', $name)),
        };
    }

    protected function resetService(string $name): void {}
}
