<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Service;

use Doctrine\ORM\EntityManager;
use Doctrine\Persistence\ManagerRegistry;
use PrecisionSoft\Doctrine\Utility\Contract\LockServiceInterface;
use PrecisionSoft\Doctrine\Utility\Exception\LockException;
use Throwable;

abstract class AbstractLockService implements LockServiceInterface
{
    protected const LOCK_KEY_SEPARATOR = '@@';
    protected const DEFAULT_ENTITY_MANAGER_NAME = 'default';

    /** @var array<string, array{count: int, lockName: string, entityManagerName: ?string}> */
    protected array $locks = [];

    protected ManagerRegistry $managerRegistry;

    /**
     * @return bool false when the lock is held elsewhere and the timeout expired
     * @throws LockException if the engine answers with anything but success or contention
     */
    abstract protected function acquireLock(string $lockName, int $timeout, EntityManager $entityManager): bool;

    /**
     * @return bool false when the lock was not established by this session
     * @throws LockException if the engine answers with anything but success or foreign ownership
     */
    abstract protected function releaseLock(string $lockName, EntityManager $entityManager): bool;

    /** @throws LockException if the lock status cannot be determined */
    abstract protected function hasLockAtDatabase(string $lockName, EntityManager $entityManager): bool;

    /**
     * @return bool whether this very session holds the lock, as opposed to any session on the server
     * @throws LockException if the lock ownership cannot be determined
     */
    abstract protected function hasLockInSession(string $lockName, EntityManager $entityManager): bool;

    /** @param array<string, mixed>|null $context */
    abstract protected function createException(
        string $message,
        int $code = 0,
        ?Throwable $previous = null,
        ?array $context = null,
    ): LockException;

    public function __construct(ManagerRegistry $managerRegistry)
    {
        $this->managerRegistry = $managerRegistry;
    }

    /**
     * @throws LockException if the lock status cannot be determined
     */
    public function hasLock(string $lockName, ?string $entityManagerName = null): bool
    {
        return $this->wrapException(function () use ($lockName, $entityManagerName): bool {
            $entityManager = $this->getEntityManager($entityManagerName);

            return $this->hasLockAtDatabase($lockName, $entityManager);
        });
    }

    /**
     * @throws LockException if the lock ownership cannot be determined
     */
    public function hasLockInCurrentSession(string $lockName, ?string $entityManagerName = null): bool
    {
        return $this->wrapException(function () use ($lockName, $entityManagerName): bool {
            $entityManager = $this->getEntityManager($entityManagerName);

            return $this->hasLockInSession($lockName, $entityManager);
        });
    }

    /**
     * @throws LockException if the lock cannot be acquired or times out
     */
    public function acquire(
        string $lockName,
        int $timeout = 0,
        ?string $entityManagerName = null,
        bool $forceRefresh = false,
    ): static {
        $lockKey = $this->buildLockKey($lockName, $entityManagerName);

        if (false === $forceRefresh && true === isset($this->locks[$lockKey])) {
            ++$this->locks[$lockKey]['count'];

            return $this;
        }

        $this->wrapException(function () use ($lockName, $timeout, $entityManagerName, $lockKey): void {
            $entityManager = $this->getEntityManager($entityManagerName);

            /* both engines stack a lock re-taken by the session already holding it, so a refresh must ask who owns it before acquiring, or it would leave the engine one level above the reference count */
            if (true === isset($this->locks[$lockKey]) && true === $this->hasLockInSession($lockName, $entityManager)) {
                return;
            }

            if (false === $this->acquireLock($lockName, $timeout, $entityManager)) {
                throw $this->createException('another operation with the same id is already in progress');
            }

            if (false === isset($this->locks[$lockKey])) {
                $this->locks[$lockKey] = [
                    'count' => 1,
                    'lockName' => $lockName,
                    'entityManagerName' => $entityManagerName,
                ];
            }
        }, \sprintf('failed acquiring lock `%s`', $lockName));

        return $this;
    }

    /**
     * A release the engine never answered keeps its bookkeeping, so the caller can retry; only an answer
     * proves the lock is gone.
     *
     * @throws LockException if $throwException is true and the lock cannot be released
     */
    public function release(
        string $lockName,
        ?string $entityManagerName = null,
        bool $throwException = false,
    ): static {
        $lockKey = $this->buildLockKey($lockName, $entityManagerName);

        if (false === isset($this->locks[$lockKey])) {
            if (true === $throwException) {
                throw $this->createException(\sprintf('the lock "%s" is not currently acquired', $lockName));
            }

            return $this;
        }

        if ($this->locks[$lockKey]['count'] > 1) {
            --$this->locks[$lockKey]['count'];

            return $this;
        }

        try {
            $released = $this->releaseLock($lockName, $this->getEntityManager($entityManagerName));
        } catch (Throwable $throwable) {
            if (true === $throwException) {
                if (true === ($throwable instanceof LockException)) {
                    throw $throwable;
                }

                throw $this->createException(
                    \sprintf('failed releasing lock `%s`: %s', $lockName, $throwable->getMessage()),
                    (int)$throwable->getCode(),
                    $throwable,
                );
            }

            return $this;
        }

        unset($this->locks[$lockKey]);

        if (false === $released && true === $throwException) {
            throw $this->createException('lock was not established by this session');
        }

        return $this;
    }

    /**
     * @param list<string> $lockNames
     * @throws LockException if any lock cannot be acquired; the ones already acquired are released on failure
     */
    public function acquireLocks(array $lockNames, int $timeout = 0, ?string $entityManagerName = null): static
    {
        \sort($lockNames);

        $acquiredLockNames = [];

        try {
            foreach ($lockNames as $lockName) {
                $this->acquire($lockName, $timeout, $entityManagerName);

                $acquiredLockNames[] = $lockName;
            }
        } catch (Throwable $throwable) {
            $this->releaseLocks(\array_reverse($acquiredLockNames), $entityManagerName);

            throw $throwable;
        }

        return $this;
    }

    /**
     * @param list<string>|null $lockNames null drains every currently held lock
     * @throws LockException if $throwException is true and any lock cannot be released
     */
    public function releaseLocks(
        ?array $lockNames = null,
        ?string $entityManagerName = null,
        bool $throwException = false,
    ): static {
        if (null !== $lockNames) {
            foreach ($lockNames as $lockName) {
                $this->releaseWithContext($lockName, $entityManagerName, $throwException, false);
            }

            return $this;
        }

        foreach (\array_values($this->locks) as $lock) {
            $lockKey = $this->buildLockKey($lock['lockName'], $lock['entityManagerName']);

            /* release() is reference counted, so releasing everything has to drain a reentrant lock's count */
            while (true === isset($this->locks[$lockKey])) {
                $countBeforeRelease = $this->locks[$lockKey]['count'];

                $this->releaseWithContext($lock['lockName'], $lock['entityManagerName'], $throwException, true);

                /* a release that neither dropped the lock nor lowered its count did not reach the engine, and retrying it here would spin */
                if (
                    true === isset($this->locks[$lockKey])
                    && $this->locks[$lockKey]['count'] >= $countBeforeRelease
                ) {
                    break;
                }
            }
        }

        return $this;
    }

    protected function buildLockKey(string $lockName, ?string $entityManagerName): string
    {
        return $lockName . static::LOCK_KEY_SEPARATOR . ($entityManagerName ?? static::DEFAULT_ENTITY_MANAGER_NAME);
    }

    /**
     * @throws LockException if $throwException is true and the lock cannot be released
     */
    protected function releaseWithContext(
        string $lockName,
        ?string $entityManagerName,
        bool $throwException,
        bool $releasedAll,
    ): void {
        try {
            $this->release($lockName, $entityManagerName, throwException: true);
        } catch (Throwable $throwable) {
            if (true === $throwException) {
                throw $this->createException(
                    $throwable->getMessage(),
                    (int)$throwable->getCode(),
                    $throwable,
                    [
                        'lockName' => $lockName,
                        'entityManagerName' => $entityManagerName,
                        'releasedAll' => $releasedAll,
                    ],
                );
            }
        }
    }

    /**
     * @throws LockException if the registered manager is not an entity manager, or runs on an unsupported platform
     */
    protected function getEntityManager(?string $entityManagerName): EntityManager
    {
        $entityManager = $this->managerRegistry->getManager($entityManagerName);

        if (false === ($entityManager instanceof EntityManager)) {
            throw $this->createException(
                \sprintf('manager "%s" is not an instance of entity manager', $entityManagerName),
            );
        }

        $this->assertPlatform($entityManager);

        return $entityManager;
    }

    /**
     * @throws LockException if the connection does not run the platform this service speaks to
     */
    protected function assertPlatform(EntityManager $entityManager): void {}

    /**
     * @throws LockException if the callback throws any Throwable
     */
    protected function wrapException(callable $callback, ?string $messagePrefix = null): mixed
    {
        try {
            return $callback();
        } catch (LockException $lockException) {
            throw $lockException;
        } catch (Throwable $throwable) {
            $message = null !== $messagePrefix
                ? \sprintf('%s: `%s`', $messagePrefix, $throwable->getMessage())
                : $throwable->getMessage();

            throw $this->createException($message, (int)$throwable->getCode(), $throwable);
        }
    }
}
