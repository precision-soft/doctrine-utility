<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Example\Service;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PrecisionSoft\Doctrine\Utility\Contract\LockServiceInterface;
use PrecisionSoft\Doctrine\Utility\Service\MysqlLockService;
use PrecisionSoft\Doctrine\Utility\Service\PostgresqlLockService;

/**
 * In an application the container aliases the interface to one implementation; the catalogue runs on three engines, so it asks the platform.
 */
class LockServiceFactory
{
    public static function create(EntityManagerInterface $entityManager, ManagerRegistry $managerRegistry): LockServiceInterface
    {
        if (true === $entityManager->getConnection()->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            return new PostgresqlLockService($managerRegistry);
        }

        return new MysqlLockService($managerRegistry);
    }
}
