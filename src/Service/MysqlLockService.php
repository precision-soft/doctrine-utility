<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Service;

use Doctrine\ORM\EntityManager;
use PrecisionSoft\Doctrine\Utility\Exception\LockException;
use PrecisionSoft\Doctrine\Utility\Exception\MysqlLockException;
use Throwable;

class MysqlLockService extends AbstractLockService
{
    protected const IS_FREE_LOCK_FREE = 1;
    protected const GET_LOCK_SUCCESS = 1;
    protected const GET_LOCK_TIMEOUT = 0;
    protected const RELEASE_LOCK_SUCCESS = 1;
    protected const RELEASE_LOCK_NOT_OWNED = 0;
    protected const MAXIMUM_LOCK_NAME_LENGTH = 64;

    protected function acquireLock(string $lockName, int $timeout, EntityManager $entityManager): bool
    {
        $acquireQuery = \sprintf(
            'SELECT GET_LOCK(%s, %s) AS lockAcquired',
            $this->prepareLockName($lockName, $entityManager),
            $timeout,
        );
        $acquireRow = $entityManager->getConnection()->executeQuery($acquireQuery)->fetchAssociative();

        if (false === $acquireRow || false === \array_key_exists('lockAcquired', $acquireRow)) {
            throw $this->createException('failed to acquire lock: invalid response');
        }

        $lockAcquired = $acquireRow['lockAcquired'];

        if (null !== $lockAcquired) {
            $lockAcquired = (int)$lockAcquired;
        }

        return match (true) {
            static::GET_LOCK_SUCCESS === $lockAcquired => true,
            static::GET_LOCK_TIMEOUT === $lockAcquired => false,
            default => throw $this->createException('failed to acquire lock: unexpected response'),
        };
    }

    protected function releaseLock(string $lockName, EntityManager $entityManager): bool
    {
        $releaseQuery = \sprintf(
            'SELECT RELEASE_LOCK(%s) AS lockReleased',
            $this->prepareLockName($lockName, $entityManager),
        );
        $releaseRow = $entityManager->getConnection()->executeQuery($releaseQuery)->fetchAssociative();

        if (false === $releaseRow || false === \array_key_exists('lockReleased', $releaseRow)) {
            throw $this->createException('failed to release lock: invalid response');
        }

        $lockReleased = $releaseRow['lockReleased'];

        if (null === $lockReleased) {
            throw $this->createException('failed to release lock: invalid response');
        }

        return match ((int)$lockReleased) {
            static::RELEASE_LOCK_SUCCESS => true,
            static::RELEASE_LOCK_NOT_OWNED => false,
            default => throw $this->createException('failed to release lock: unexpected response'),
        };
    }

    protected function hasLockAtDatabase(string $lockName, EntityManager $entityManager): bool
    {
        $lockStatusQuery = \sprintf(
            'SELECT IS_FREE_LOCK(%s) AS lockIsFree',
            $this->prepareLockName($lockName, $entityManager),
        );
        $lockStatusRow = $entityManager->getConnection()->executeQuery($lockStatusQuery)->fetchAssociative();

        if (false === $lockStatusRow || false === isset($lockStatusRow['lockIsFree'])) {
            throw $this->createException('failed to check lock status');
        }

        return static::IS_FREE_LOCK_FREE !== (int)$lockStatusRow['lockIsFree'];
    }

    protected function hasLockInSession(string $lockName, EntityManager $entityManager): bool
    {
        $ownerQuery = \sprintf(
            'SELECT IS_USED_LOCK(%s) AS lockOwner, CONNECTION_ID() AS sessionId',
            $this->prepareLockName($lockName, $entityManager),
        );
        $ownerRow = $entityManager->getConnection()->executeQuery($ownerQuery)->fetchAssociative();

        if (
            false === $ownerRow
            || false === \array_key_exists('lockOwner', $ownerRow)
            || false === isset($ownerRow['sessionId'])
        ) {
            throw $this->createException('failed to check lock ownership');
        }

        if (null === $ownerRow['lockOwner']) {
            return false;
        }

        return (int)$ownerRow['lockOwner'] === (int)$ownerRow['sessionId'];
    }

    protected function createException(
        string $message,
        int $code = 0,
        ?Throwable $previous = null,
        ?array $context = null,
    ): LockException {
        return new MysqlLockException($message, $code, $previous, $context);
    }

    protected function prepareLockName(string $lockName, EntityManager $entityManager): string
    {
        if (\strlen($lockName) > static::MAXIMUM_LOCK_NAME_LENGTH) {
            $lockName = \substr($lockName, 0, 10) . '>>' . \md5($lockName) . '<<' . \substr($lockName, -10);
        }

        return $entityManager->getConnection()->quote($lockName);
    }
}
