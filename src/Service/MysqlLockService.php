<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Service;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\Persistence\ManagerRegistry;
use PrecisionSoft\Doctrine\Utility\Exception\MysqlLockException;
use Throwable;

class MysqlLockService
{
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

    public function isLocked(string $lockName, ?string $entityManagerName = null): bool
    {
        try {
            /** @var EntityManager $entityManager */
            $entityManager = $this->managerRegistry->getManager($entityManagerName);
            $connection = $entityManager->getConnection();
            $lockStatusQuery = \sprintf(
                'SELECT IS_FREE_LOCK(%s) AS lockIsFree',
                $this->prepareLockName($lockName, $entityManagerName),
            );
            $lockStatusRow = $connection->executeQuery($lockStatusQuery)->fetchAssociative();

            if (false === $lockStatusRow || false === isset($lockStatusRow['lockIsFree'])) {
                throw new MysqlLockException('failed to check lock status');
            }

            return 1 !== (int)$lockStatusRow['lockIsFree'];
        } catch (Throwable $throwable) {
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
            $preparedLockName = $this->prepareLockName($lockName, $entityManagerName);
            /** @var EntityManager $entityManager */
            $entityManager = $this->managerRegistry->getManager($entityManagerName);
            $connection = $entityManager->getConnection();
            $acquireQuery = \sprintf('SELECT GET_LOCK(%s, %s) AS lockAcquired', $preparedLockName, $timeout);
            $acquireRow = $connection->executeQuery($acquireQuery)->fetchAssociative();

            if (false === $acquireRow || false === isset($acquireRow['lockAcquired'])) {
                throw new MysqlLockException('failed to acquire lock: invalid response');
            }

            switch ((int)$acquireRow['lockAcquired']) {
                case 1:
                    if (false === isset($this->locks[$lockKey])) {
                        $this->locks[$lockKey] = [
                            'preparedLockName' => $preparedLockName,
                            'count' => 0,
                            'lockName' => $lockName,
                            'entityManagerName' => $entityManagerName,
                        ];
                    }

                    ++$this->locks[$lockKey]['count'];

                    break;
                case 0:
                    throw new MysqlLockException('another operation with the same id is already in progress');
                default:
                    throw new MysqlLockException(
                        'an error occurred (such as running out of memory or the thread was killed)',
                    );
            }
        } catch (Throwable $throwable) {
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

            /** @var EntityManager $entityManager */
            $entityManager = $this->managerRegistry->getManager($entityManagerName);
            $connection = $entityManager->getConnection();
            $releaseQuery = \sprintf('SELECT RELEASE_LOCK(%s) AS lockReleased', $this->locks[$lockKey]['preparedLockName']);
            $releaseRow = $connection->executeQuery($releaseQuery)->fetchAssociative();

            if (false === $releaseRow || false === isset($releaseRow['lockReleased'])) {
                throw new MysqlLockException('failed to release lock: invalid response');
            }

            switch ((int)$releaseRow['lockReleased']) {
                case 1:
                    unset($this->locks[$lockKey]);

                    break;
                case 0:
                    throw new MysqlLockException('lock was not established by this thread');
                default:
                    throw new MysqlLockException('the named lock did not exist');
            }
        } catch (Throwable $throwable) {
            if (true === $throwException) {
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
            foreach ($this->locks as $lockData) {
                try {
                    $this->release($lockData['lockName'], $lockData['entityManagerName']);
                } catch (Throwable $throwable) {
                    if (true === $throwException) {
                        throw new MysqlLockException($throwable->getMessage(), (int)$throwable->getCode(), $throwable);
                    }
                }
            }
        } else {
            foreach ($lockNames as $lockName) {
                try {
                    $this->release($lockName, $entityManagerName);
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

    private function prepareLockName(string $lockName, ?string $entityManagerName = null): string
    {
        if (64 < \strlen($lockName)) {
            $lockName = \substr($lockName, 0, 10) . '>>' . \md5($lockName) . '<<' . \substr($lockName, -10);
        }

        /** @var EntityManager $entityManager */
        $entityManager = $this->managerRegistry->getManager($entityManagerName);

        /** @var Connection $connection */
        $connection = $entityManager->getConnection();

        return $connection->quote($lockName);
    }
}
