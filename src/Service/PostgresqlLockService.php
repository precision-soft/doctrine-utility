<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Service;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManager;
use PrecisionSoft\Doctrine\Utility\Exception\LockException;
use PrecisionSoft\Doctrine\Utility\Exception\PostgresqlLockException;
use Throwable;

class PostgresqlLockService extends AbstractLockService
{
    /* a two key advisory lock is recorded with objsubid 2; a single key one splits the same bigint over classid and objid with objsubid 1, so the column separates two lock spaces that otherwise collide */
    protected const ADVISORY_LOCK_OBJECT_SUB_ID = 2;
    protected const POLL_INTERVAL_MICROSECONDS = 100_000;
    protected const LOCK_KEY_BYTE_LENGTH = 8;
    protected const SIGNED_INT32_MAXIMUM = 0x7FFFFFFF;
    protected const UNSIGNED_INT32_RANGE = 0x100000000;

    protected function acquireLock(string $lockName, int $timeout, EntityManager $entityManager): bool
    {
        $connection = $entityManager->getConnection();
        $lockKeys = $this->prepareLockKeys($lockName);
        $deadline = \microtime(true) + $timeout;

        do {
            if (true === $this->isDatabaseTrue($connection->fetchOne('SELECT pg_try_advisory_lock(?, ?)', $lockKeys))) {
                return true;
            }

            if (\microtime(true) >= $deadline) {
                return false;
            }

            \usleep(static::POLL_INTERVAL_MICROSECONDS);
        } while (true);
    }

    protected function releaseLock(string $lockName, EntityManager $entityManager): bool
    {
        return $this->isDatabaseTrue($entityManager->getConnection()->fetchOne(
            'SELECT pg_advisory_unlock(?, ?)',
            $this->prepareLockKeys($lockName),
        ));
    }

    protected function hasLockAtDatabase(string $lockName, EntityManager $entityManager): bool
    {
        return $this->countAdvisoryLocks($lockName, $entityManager, false) > 0;
    }

    protected function hasLockInSession(string $lockName, EntityManager $entityManager): bool
    {
        return $this->countAdvisoryLocks($lockName, $entityManager, true) > 0;
    }

    protected function createException(
        string $message,
        int $code = 0,
        ?Throwable $previous = null,
        ?array $context = null,
    ): LockException {
        return new PostgresqlLockException($message, $code, $previous, $context);
    }

    protected function assertPlatform(EntityManager $entityManager): void
    {
        if (false === ($entityManager->getConnection()->getDatabasePlatform() instanceof PostgreSQLPlatform)) {
            throw $this->createException('postgresql lock service requires a postgresql connection');
        }
    }

    /**
     * @throws PostgresqlLockException if the lock status cannot be determined
     */
    protected function countAdvisoryLocks(
        string $lockName,
        EntityManager $entityManager,
        bool $currentSessionOnly,
    ): int {
        $countQuery = \sprintf(
            "SELECT COUNT(*) FROM pg_locks WHERE locktype = 'advisory'"
            . ' AND classid = (?::int)::oid AND objid = (?::int)::oid AND objsubid = %d AND granted = TRUE%s',
            static::ADVISORY_LOCK_OBJECT_SUB_ID,
            true === $currentSessionOnly ? ' AND pid = pg_backend_pid()' : '',
        );
        $count = $entityManager->getConnection()->fetchOne($countQuery, $this->prepareLockKeys($lockName));

        if (false === \is_numeric($count)) {
            throw $this->createException('failed to check lock status');
        }

        return (int)$count;
    }

    /**
     * @return array{int, int}
     * @throws PostgresqlLockException if the lock name cannot be hashed
     */
    protected function prepareLockKeys(string $lockName): array
    {
        $hash = \hash('sha256', $lockName, true);
        $keyParts = \unpack('NclassId/NobjectId', \substr($hash, 0, static::LOCK_KEY_BYTE_LENGTH));

        if (false === $keyParts) {
            throw $this->createException('failed to hash lock name');
        }

        return [$this->toSignedInt32($keyParts['classId']), $this->toSignedInt32($keyParts['objectId'])];
    }

    protected function isDatabaseTrue(mixed $value): bool
    {
        return true === $value || 1 === $value || '1' === $value || 't' === $value;
    }

    protected function toSignedInt32(int $value): int
    {
        return $value > static::SIGNED_INT32_MAXIMUM ? $value - static::UNSIGNED_INT32_RANGE : $value;
    }
}
