<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Contract;

use PrecisionSoft\Doctrine\Utility\Exception\LockException;

interface LockServiceInterface
{
    /**
     * Whether any session on the server holds the lock.
     *
     * @throws LockException if the lock status cannot be determined
     */
    public function hasLock(string $lockName, ?string $entityManagerName = null): bool;

    /**
     * Whether this very connection holds the lock, which `hasLock()` cannot tell apart from a lock held elsewhere.
     *
     * @throws LockException if the lock ownership cannot be determined
     */
    public function hasLockInCurrentSession(string $lockName, ?string $entityManagerName = null): bool;

    /** @throws LockException if the lock cannot be acquired or times out */
    public function acquire(
        string $lockName,
        int $timeout = 0,
        ?string $entityManagerName = null,
        bool $forceRefresh = false,
    ): static;

    /** @throws LockException if $throwException is true and the lock cannot be released */
    public function release(
        string $lockName,
        ?string $entityManagerName = null,
        bool $throwException = false,
    ): static;

    /**
     * @param list<string> $lockNames
     * @throws LockException if any lock cannot be acquired; the ones already acquired are released on failure
     */
    public function acquireLocks(array $lockNames, int $timeout = 0, ?string $entityManagerName = null): static;

    /**
     * @param list<string>|null $lockNames null drains every currently held lock
     * @throws LockException if $throwException is true and any lock cannot be released
     */
    public function releaseLocks(
        ?array $lockNames = null,
        ?string $entityManagerName = null,
        bool $throwException = false,
    ): static;
}
