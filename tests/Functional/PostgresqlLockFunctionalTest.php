<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Test\Functional;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PrecisionSoft\Doctrine\Utility\Exception\PostgresqlLockException;
use PrecisionSoft\Doctrine\Utility\Service\PostgresqlLockService;
use PrecisionSoft\Doctrine\Utility\Test\Utility\IntegrationDatabase;
use PrecisionSoft\Doctrine\Utility\Test\Utility\IntegrationManagerRegistry;
use PrecisionSoft\Doctrine\Utility\Test\Utility\SkipIntegrationException;
use ReflectionMethod;

/** @internal */
#[Group('integration')]
final class PostgresqlLockFunctionalTest extends TestCase
{
    /** @var list<Connection> */
    private array $connections = [];

    protected function tearDown(): void
    {
        foreach ($this->connections as $connection) {
            $connection->close();
        }

        $this->connections = [];

        parent::tearDown();
    }

    public function testAcquireReleaseAndReentrantReferenceCounting(): void
    {
        $service = $this->createService();

        static::assertFalse($service->hasLock('orders'));

        $service->acquire('orders')->acquire('orders');
        static::assertTrue($service->hasLock('orders'));

        $service->release('orders', throwException: true);
        static::assertTrue($service->hasLock('orders'));

        $service->release('orders', throwException: true);
        static::assertFalse($service->hasLock('orders'));
    }

    public function testASecondSessionCannotAcquireTheSameLock(): void
    {
        $holder = $this->createService();
        $contender = $this->createService();
        $holder->acquire('exclusive');

        try {
            $contender->acquire('exclusive');
            static::fail('the contender acquired a held advisory lock');
        } catch (PostgresqlLockException $exception) {
            static::assertStringContainsString('already in progress', $exception->getMessage());
        } finally {
            $holder->releaseLocks(null, throwException: true);
        }
    }

    public function testMultipleLocksAreAcquiredAndReleasedTogether(): void
    {
        $service = $this->createService();

        $service->acquireLocks(['beta', 'alpha']);
        static::assertTrue($service->hasLock('alpha'));
        static::assertTrue($service->hasLock('beta'));

        $service->releaseLocks(null, throwException: true);
        static::assertFalse($service->hasLock('alpha'));
        static::assertFalse($service->hasLock('beta'));
    }

    public function testForceRefreshDoesNotStackAnExtraLevelOnTheServer(): void
    {
        $service = $this->createService();
        $observer = $this->createService();

        $service->acquire('refreshable');
        $service->acquire('refreshable', forceRefresh: true);

        static::assertTrue($observer->hasLock('refreshable'));

        /* advisory locks are reentrant per session, so a refresh that simply re-ran pg_try_advisory_lock would need a second unlock to free the key */
        $service->release('refreshable', throwException: true);

        static::assertFalse($observer->hasLock('refreshable'));
    }

    public function testAcquireWaitsForTheTimeoutBeforeGivingUp(): void
    {
        $holder = $this->createService();
        $contender = $this->createService();
        $holder->acquire('contended');

        $startedAt = \microtime(true);

        try {
            $contender->acquire('contended', 1);
            static::fail('the contender acquired a held advisory lock');
        } catch (PostgresqlLockException $exception) {
            static::assertGreaterThanOrEqual(1.0, \microtime(true) - $startedAt);
        } finally {
            $holder->releaseLocks(null, throwException: true);
        }
    }

    public function testAcquireRejectsANegativeTimeout(): void
    {
        $service = $this->createService();

        $this->expectException(PostgresqlLockException::class);
        $this->expectExceptionMessage('lock timeout must not be negative');

        $service->acquire('negative', -1);
    }

    public function testReleasingALockThatWasNeverAcquiredThrowsOnlyOnRequest(): void
    {
        $service = $this->createService();

        $service->release('never-taken');

        $this->expectException(PostgresqlLockException::class);
        $this->expectExceptionMessage('the lock "never-taken" is not currently acquired');

        $service->release('never-taken', throwException: true);
    }

    public function testAcquireLocksReleasesTheLocksItAlreadyTookWhenOneFails(): void
    {
        $blocker = $this->createService();
        $service = $this->createService();
        $observer = $this->createService();
        $blocker->acquire('second');

        try {
            $service->acquireLocks(['first', 'second']);
            static::fail('the contended lock was acquired');
        } catch (PostgresqlLockException $exception) {
            static::assertFalse($observer->hasLock('first'), 'the lock taken before the failure must be rolled back');
        } finally {
            $blocker->releaseLocks(null, throwException: true);
        }
    }

    public function testHasLockIgnoresASingleKeyAdvisoryLockThatSharesTheSameKeyHalves(): void
    {
        $service = $this->createService();
        $connection = $this->createConnection('DATABASE_URL_POSTGRESQL');

        [$classId, $objectId] = $this->readLockKeys($service, 'colliding');
        $singleKey = ($classId << 32) | ($objectId & 0xFFFFFFFF);

        $connection->executeQuery('SELECT pg_advisory_lock(?)', [$singleKey]);

        static::assertFalse(
            $service->hasLock('colliding'),
            'a one key advisory lock lands on the same classid and objid, and is separated only by objsubid',
        );
    }

    public function testTheServiceRefusesANonPostgresqlConnection(): void
    {
        $entityManager = IntegrationDatabase::createEntityManager($this->createConnection('DATABASE_URL_MYSQL'));
        $service = new PostgresqlLockService(new IntegrationManagerRegistry($entityManager));

        $this->expectException(PostgresqlLockException::class);
        $this->expectExceptionMessage('postgresql lock service requires a postgresql connection');

        $service->hasLock('anything');
    }

    public function testHasLockInCurrentSessionSeparatesTheOwnerFromABystander(): void
    {
        $holder = $this->createService();
        $bystander = $this->createService();

        $holder->acquire('owned');

        try {
            static::assertTrue($holder->hasLockInCurrentSession('owned'));
            static::assertFalse($bystander->hasLockInCurrentSession('owned'));
            static::assertTrue(
                $bystander->hasLock('owned'),
                'the cluster wide check cannot tell "held by me" from "held by anyone"',
            );
        } finally {
            $holder->releaseLocks(null, throwException: true);
        }

        static::assertFalse($holder->hasLockInCurrentSession('owned'));
    }

    /** @return array{int, int} */
    private function readLockKeys(PostgresqlLockService $service, string $lockName): array
    {
        $reflectionMethod = new ReflectionMethod(PostgresqlLockService::class, 'prepareLockKeys');

        /** @var array{int, int} $lockKeys */
        $lockKeys = $reflectionMethod->invoke($service, $lockName);

        return $lockKeys;
    }

    private function createService(): PostgresqlLockService
    {
        $entityManager = IntegrationDatabase::createEntityManager($this->createConnection('DATABASE_URL_POSTGRESQL'));

        return new PostgresqlLockService(new IntegrationManagerRegistry($entityManager));
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
}
