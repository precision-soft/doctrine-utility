<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Service;

use Doctrine\ORM\EntityManager;
use Doctrine\Persistence\ManagerRegistry;
use PrecisionSoft\Doctrine\Utility\Exception\MysqlLockException;
use Throwable;

class MysqlLockService
{
    private const IS_FREE_LOCK_FREE = 1;
    private const GET_LOCK_SUCCESS = 1;
    private const GET_LOCK_TIMEOUT = 0;
    private const RELEASE_LOCK_SUCCESS = 1;
    private const RELEASE_LOCK_NOT_OWNED = 0;

    private ManagerRegistry $managerRegistry;

    /**
     * @var array<string, array{preparedLockName: string, count: int, lockName: string, entityManagerName: ?string}>
     */
    private array $locks;

    public function __construct(ManagerRegistry $managerRegistry)
    {
        $this->managerRegistry = $managerRegistry;
        $this->locks = [];
    }

    public function hasLock(string $lockName, ?string $entityManagerName = null): bool
    {
        try {
            $entityManager = $this->getEntityManager($entityManagerName);
            $connection = $entityManager->getConnection();
            $lockStatusQuery = \sprintf(
                'SELECT IS_FREE_LOCK(%s) AS lockIsFree',
                $this->prepareLockName($lockName, $entityManager),
            );
            $lockStatusRow = $connection->executeQuery($lockStatusQuery)->fetchAssociative();

            if (false === $lockStatusRow || false === isset($lockStatusRow['lockIsFree'])) {
                throw new MysqlLockException('failed to check lock status');
            }

            return self::IS_FREE_LOCK_FREE !== (int)$lockStatusRow['lockIsFree'];
        } catch (Throwable $throwable) {
            if (true === $throwable instanceof MysqlLockException) {
                throw $throwable;
            }

            throw new MysqlLockException($throwable->getMessage(), (int)$throwable->getCode(), $throwable);
        }
    }

    public function acquire(
        string $lockName,
        int $timeout = 0,
        ?string $entityManagerName = null,
        bool $forceRefresh = false,
    ): self {
        $lockKey = $this->buildLockKey($lockName, $entityManagerName);

        if (false === $forceRefresh && true === isset($this->locks[$lockKey])) {
            ++$this->locks[$lockKey]['count'];

            return $this;
        }

        try {
            $entityManager = $this->getEntityManager($entityManagerName);
            $preparedLockName = $this->prepareLockName($lockName, $entityManager);
            $connection = $entityManager->getConnection();
            $acquireQuery = \sprintf('SELECT GET_LOCK(%s, %s) AS lockAcquired', $preparedLockName, $timeout);
            $acquireRow = $connection->executeQuery($acquireQuery)->fetchAssociative();

            if (false === $acquireRow || false === \array_key_exists('lockAcquired', $acquireRow)) {
                throw new MysqlLockException('failed to acquire lock: invalid response');
            }

            $lockAcquired = $acquireRow['lockAcquired'];

            if (null !== $lockAcquired) {
                $lockAcquired = (int)$lockAcquired;
            }

            switch (true) {
                case self::GET_LOCK_SUCCESS === $lockAcquired:
                    if (true === isset($this->locks[$lockKey])) {
                        $this->locks[$lockKey]['preparedLockName'] = $preparedLockName;
                    } else {
                        $this->locks[$lockKey] = [
                            'preparedLockName' => $preparedLockName,
                            'count' => 0,
                            'lockName' => $lockName,
                            'entityManagerName' => $entityManagerName,
                        ];
                    }

                    ++$this->locks[$lockKey]['count'];

                    break;
                case self::GET_LOCK_TIMEOUT === $lockAcquired:
                    throw new MysqlLockException('another operation with the same id is already in progress');
                default:
                    throw new MysqlLockException('failed to acquire lock: unexpected response');
            }
        } catch (Throwable $throwable) {
            if (true === $throwable instanceof MysqlLockException) {
                throw $throwable;
            }

            throw new MysqlLockException(
                \sprintf('failed acquiring lock `%s`: `%s`', $lockName, $throwable->getMessage()),
                (int)$throwable->getCode(),
                $throwable,
            );
        }

        return $this;
    }

    public function release(
        string $lockName,
        ?string $entityManagerName = null,
        bool $throwException = false,
    ): self {
        $lockKey = $this->buildLockKey($lockName, $entityManagerName);

        try {
            if (false === isset($this->locks[$lockKey])) {
                throw new MysqlLockException(
                    \sprintf('the lock "%s" is not currently acquired', $lockName),
                );
            }

            --$this->locks[$lockKey]['count'];

            if (0 < $this->locks[$lockKey]['count']) {
                return $this;
            }

            $entityManager = $this->getEntityManager($entityManagerName);
            $connection = $entityManager->getConnection();
            $releaseQuery = \sprintf('SELECT RELEASE_LOCK(%s) AS lockReleased', $this->locks[$lockKey]['preparedLockName']);
            $releaseRow = $connection->executeQuery($releaseQuery)->fetchAssociative();

            if (false === $releaseRow || false === \array_key_exists('lockReleased', $releaseRow)) {
                throw new MysqlLockException('failed to release lock: invalid response');
            }

            $lockReleased = $releaseRow['lockReleased'];

            if (null === $lockReleased) {
                throw new MysqlLockException('failed to release lock: invalid response');
            }

            switch ((int)$lockReleased) {
                case self::RELEASE_LOCK_SUCCESS:
                    unset($this->locks[$lockKey]);

                    break;
                case self::RELEASE_LOCK_NOT_OWNED:
                    throw new MysqlLockException('lock was not established by this thread');
                default:
                    throw new MysqlLockException('failed to release lock: unexpected response');
            }
        } catch (Throwable $throwable) {
            unset($this->locks[$lockKey]);

            if (true === $throwException) {
                if (true === $throwable instanceof MysqlLockException) {
                    throw $throwable;
                }

                throw new MysqlLockException(
                    \sprintf('failed releasing lock `%s`: %s', $lockName, $throwable->getMessage()),
                    (int)$throwable->getCode(),
                    $throwable,
                );
            }
        }

        return $this;
    }

    public function acquireLocks(array $lockNames, int $timeout = 0, ?string $entityManagerName = null): self
    {
        \sort($lockNames);

        try {
            foreach ($lockNames as $lockName) {
                $this->acquire($lockName, $timeout, $entityManagerName);
            }
        } catch (Throwable $throwable) {
            $this->releaseLocks($lockNames, $entityManagerName);

            if (true === $throwable instanceof MysqlLockException) {
                throw $throwable;
            }

            throw new MysqlLockException($throwable->getMessage(), (int)$throwable->getCode(), $throwable);
        }

        return $this;
    }

    public function releaseLocks(
        ?array $lockNames = null,
        ?string $entityManagerName = null,
        bool $throwException = false,
    ): self {
        if (null === $lockNames) {
            $locksToRelease = $this->locks;

            foreach ($locksToRelease as $lockData) {
                try {
                    $this->release($lockData['lockName'], $lockData['entityManagerName'], throwException: true);
                } catch (Throwable $throwable) {
                    if (true === $throwException) {
                        throw new MysqlLockException($throwable->getMessage(), (int)$throwable->getCode(), $throwable);
                    }
                }
            }
        } else {
            foreach ($lockNames as $lockName) {
                try {
                    $this->release($lockName, $entityManagerName, throwException: true);
                } catch (Throwable $throwable) {
                    if (true === $throwException) {
                        throw new MysqlLockException($throwable->getMessage(), (int)$throwable->getCode(), $throwable);
                    }
                }
            }
        }

        return $this;
    }

    private function buildLockKey(string $lockName, ?string $entityManagerName): string
    {
        return $lockName . '@@' . ($entityManagerName ?? 'default');
    }

    private function prepareLockName(string $lockName, EntityManager $entityManager): string
    {
        if (64 < \strlen($lockName)) {
            $lockName = \substr($lockName, 0, 10) . '>>' . \md5($lockName) . '<<' . \substr($lockName, -10);
        }

        return $entityManager->getConnection()->quote($lockName);
    }

    private function getEntityManager(?string $entityManagerName): EntityManager
    {
        $entityManager = $this->managerRegistry->getManager($entityManagerName);

        if (false === $entityManager instanceof EntityManager) {
            throw new MysqlLockException(\sprintf('Manager "%s" is not an instance of EntityManager.', $entityManagerName));
        }

        return $entityManager;
    }
}
