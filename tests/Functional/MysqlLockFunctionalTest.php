<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Test\Functional;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PrecisionSoft\Doctrine\Utility\Exception\MysqlLockException;
use PrecisionSoft\Doctrine\Utility\Service\MysqlLockService;
use PrecisionSoft\Doctrine\Utility\Test\Utility\IntegrationDatabase;
use PrecisionSoft\Doctrine\Utility\Test\Utility\IntegrationManagerRegistry;
use PrecisionSoft\Doctrine\Utility\Test\Utility\SkipIntegrationException;

/**
 * "Is it really held" is asked from a third connection: the owner cannot tell "held by me" from "held at all".
 *
 * @internal
 */
#[Group('integration')]
final class MysqlLockFunctionalTest extends TestCase
{
    /** @var array<int, Connection> */
    private array $connections = [];

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testAcquireHoldsTheLockOnTheServerAndReleaseFreesIt(string $environmentVariable): void
    {
        $lockService = $this->createLockService($environmentVariable);
        $observer = $this->createConnection($environmentVariable);
        $lockName = 'integration-lock-basic';

        $lockService->acquire($lockName);

        static::assertTrue($lockService->hasLock($lockName));
        static::assertFalse($this->isFreeLock($observer, $lockName), 'an independent session must see the lock as taken');

        $lockService->release($lockName);

        static::assertFalse($lockService->hasLock($lockName));
        static::assertTrue($this->isFreeLock($observer, $lockName));
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testReentrantAcquireIsReferenceCountedAgainstTheServer(string $environmentVariable): void
    {
        $lockService = $this->createLockService($environmentVariable);
        $observer = $this->createConnection($environmentVariable);
        $lockName = 'integration-lock-reentrant';

        $lockService->acquire($lockName);
        $lockService->acquire($lockName);

        $lockService->release($lockName);

        static::assertFalse(
            $this->isFreeLock($observer, $lockName),
            'the first release only decrements the reference count — the server must still hold the lock',
        );

        $lockService->release($lockName);

        static::assertTrue($this->isFreeLock($observer, $lockName));
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testASecondSessionCannotTakeAHeldLock(string $environmentVariable): void
    {
        $holder = $this->createLockService($environmentVariable);
        $contender = $this->createLockService($environmentVariable);
        $lockName = 'integration-lock-contended';

        $holder->acquire($lockName);

        $this->expectException(MysqlLockException::class);
        $this->expectExceptionMessage('another operation with the same id is already in progress');

        try {
            $contender->acquire($lockName, timeout: 0);
        } finally {
            $holder->release($lockName);
        }
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testReleaseLocksWithoutArgumentsDrainsEveryHeldLockIncludingReentrantOnes(string $environmentVariable): void
    {
        $lockService = $this->createLockService($environmentVariable);
        $observer = $this->createConnection($environmentVariable);
        $reentrantLockName = 'integration-lock-drain-reentrant';
        $plainLockName = 'integration-lock-drain-plain';

        $lockService->acquire($reentrantLockName);
        $lockService->acquire($reentrantLockName);
        $lockService->acquire($reentrantLockName);
        $lockService->acquire($plainLockName);

        $lockService->releaseLocks();

        static::assertTrue(
            $this->isFreeLock($observer, $reentrantLockName),
            'release-all must fully drain a reentrant lock, not just decrement it once',
        );
        static::assertTrue($this->isFreeLock($observer, $plainLockName));
        static::assertFalse($lockService->hasLock($reentrantLockName));
        static::assertFalse($lockService->hasLock($plainLockName));
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testAcquireLocksReleasesTheLocksItAlreadyTookWhenOneFails(string $environmentVariable): void
    {
        $holder = $this->createLockService($environmentVariable);
        $contender = $this->createLockService($environmentVariable);
        $observer = $this->createConnection($environmentVariable);
        $freeLockName = 'integration-lock-batch-a';
        $takenLockName = 'integration-lock-batch-b';

        $holder->acquire($takenLockName);

        try {
            $contender->acquireLocks([$freeLockName, $takenLockName], timeout: 0);
            static::fail('acquireLocks must fail when one of the locks is held elsewhere');
        } catch (MysqlLockException) {
            static::assertTrue(
                $this->isFreeLock($observer, $freeLockName),
                'the lock the batch did take must be rolled back, or a failed batch leaks a lock until the connection dies',
            );
        } finally {
            $holder->release($takenLockName);
        }
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testALockNameLongerThanTheServerLimitIsStillUsable(string $environmentVariable): void
    {
        $lockService = $this->createLockService($environmentVariable);
        $lockName = \str_repeat('long-lock-name-', 10);

        static::assertGreaterThan(
            64,
            \strlen($lockName),
            'the fixture must exceed the server limit, otherwise this asserts nothing',
        );

        $lockService->acquire($lockName);

        static::assertTrue($lockService->hasLock($lockName));

        $lockService->release($lockName);

        static::assertFalse($lockService->hasLock($lockName));
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testReleasingALockThatWasNeverAcquiredThrowsOnlyOnRequest(string $environmentVariable): void
    {
        $lockService = $this->createLockService($environmentVariable);
        $lockName = 'integration-lock-never-taken';

        $lockService->release($lockName);

        $this->expectException(MysqlLockException::class);
        $this->expectExceptionMessage('the lock "integration-lock-never-taken" is not currently acquired');

        $lockService->release($lockName, throwException: true);
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testForceRefreshDoesNotStackAnExtraLevelOnTheServer(string $environmentVariable): void
    {
        $lockService = $this->createLockService($environmentVariable);
        $observer = $this->createConnection($environmentVariable);
        $lockName = 'integration-lock-force-refresh';

        $lockService->acquire($lockName);
        $lockService->acquire($lockName, forceRefresh: true);

        static::assertFalse($this->isFreeLock($observer, $lockName));

        /* GET_LOCK is reentrant on the server too, so a refresh that simply re-ran it would need a second RELEASE_LOCK to free the name */
        $lockService->release($lockName, throwException: true);

        static::assertTrue($this->isFreeLock($observer, $lockName));
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testHasLockInCurrentSessionSeparatesTheOwnerFromABystander(string $environmentVariable): void
    {
        $holder = $this->createLockService($environmentVariable);
        $bystander = $this->createLockService($environmentVariable);
        $lockName = 'integration-lock-ownership';

        $holder->acquire($lockName);

        try {
            static::assertTrue($holder->hasLockInCurrentSession($lockName));
            static::assertFalse($bystander->hasLockInCurrentSession($lockName));
            static::assertTrue(
                $bystander->hasLock($lockName),
                'the cluster wide check cannot tell "held by me" from "held by anyone"',
            );
        } finally {
            $holder->releaseLocks(null, throwException: true);
        }

        static::assertFalse($holder->hasLockInCurrentSession($lockName));
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testANegativeTimeoutIsRefusedBeforeAnyQuery(string $environmentVariable): void
    {
        $lockService = $this->createLockService($environmentVariable);
        $observer = $this->createConnection($environmentVariable);
        $lockName = 'integration-lock-negative-timeout';

        /* measured on the servers: mysql 8.4 waits forever on GET_LOCK(name, -1), mariadb 11.8 answers null */
        try {
            $lockService->acquire($lockName, -1);
            static::fail('a negative timeout must be refused');
        } catch (MysqlLockException $mysqlLockException) {
            static::assertSame('lock timeout must not be negative', $mysqlLockException->getMessage());
        }

        static::assertTrue($this->isFreeLock($observer, $lockName));
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testABookkeptLockOutlivesAClosedConnection(string $environmentVariable): void
    {
        $connection = $this->createConnection($environmentVariable);
        $lockService = $this->createLockServiceOn($connection);
        $observer = $this->createConnection($environmentVariable);
        $lockName = 'integration-lock-closed-connection';

        $lockService->acquire($lockName);
        $connection->close();

        /* the fast path asks nobody: the reference count says held, the server holds nothing for the new session */
        $lockService->acquire($lockName);
        static::assertFalse($lockService->hasLockInCurrentSession($lockName));
        static::assertTrue($this->isFreeLock($observer, $lockName));

        $lockService->acquire($lockName, forceRefresh: true);
        static::assertTrue($lockService->hasLockInCurrentSession($lockName));

        $lockService->releaseLocks(null, throwException: true);
        static::assertTrue($this->isFreeLock($observer, $lockName));
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testAClosedSessionReleasesItsLockOnTheServer(string $environmentVariable): void
    {
        $connection = $this->createConnection($environmentVariable);
        $lockService = $this->createLockServiceOn($connection);
        $observer = $this->createConnection($environmentVariable);
        $lockName = 'integration-lock-closed-session';

        $lockService->acquire($lockName);
        static::assertFalse($this->isFreeLock($observer, $lockName));

        $connection->close();

        static::assertTrue(
            $this->isFreeAfterTheDisconnectSettles($observer, $lockName),
            'a named lock lives exactly as long as its session',
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->connections as $connection) {
            $connection->close();
        }

        $this->connections = [];

        parent::tearDown();
    }

    private function createLockService(string $environmentVariable): MysqlLockService
    {
        return $this->createLockServiceOn($this->createConnection($environmentVariable));
    }

    private function createLockServiceOn(Connection $connection): MysqlLockService
    {
        return new MysqlLockService(new IntegrationManagerRegistry(IntegrationDatabase::createEntityManager($connection)));
    }

    private function createConnection(string $environmentVariable): Connection
    {
        try {
            $connection = IntegrationDatabase::createConnection($environmentVariable);
        } catch (SkipIntegrationException $skipIntegrationException) {
            static::markTestSkipped($skipIntegrationException->getMessage());
        }

        $this->connections[] = $connection;

        return $connection;
    }

    /**
     * mysql 8.4 tears the session down a moment after the disconnect, so an observer may still see the lock.
     */
    private function isFreeAfterTheDisconnectSettles(Connection $observer, string $lockName): bool
    {
        $deadline = \microtime(true) + 5;

        do {
            $isFree = $this->isFreeLock($observer, $lockName);

            if (true === $isFree) {
                return true;
            }

            \usleep(50_000);
        } while (\microtime(true) < $deadline);

        return $isFree;
    }

    private function isFreeLock(Connection $connection, string $lockName): bool
    {
        return 1 === (int)$connection->executeQuery(
            'SELECT IS_FREE_LOCK(?) AS lockIsFree',
            [$lockName],
        )->fetchOne();
    }
}
