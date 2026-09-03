<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Test\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManager;
use Doctrine\Persistence\ManagerRegistry;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use PrecisionSoft\Doctrine\Utility\Exception\PostgresqlLockException;
use PrecisionSoft\Doctrine\Utility\Service\PostgresqlLockService;
use ReflectionMethod;
use ReflectionProperty;

/**
 * The statements and the key derivation, off the server: the functional suite proves them against postgresql.
 *
 * @internal
 */
final class PostgresqlLockServiceTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const SIGNED_INT32_MINIMUM = -2147483648;
    private const SIGNED_INT32_MAXIMUM = 2147483647;

    private Connection&MockInterface $connection;
    private PostgresqlLockService $postgresqlLockService;

    public function testPrepareLockKeysIsDeterministicAndStaysInsideInt32(): void
    {
        $reflectionMethod = new ReflectionMethod(PostgresqlLockService::class, 'prepareLockKeys');

        /** @var array{int, int} $lockKeys */
        $lockKeys = $reflectionMethod->invoke($this->postgresqlLockService, 'exclusive');

        static::assertSame($lockKeys, $reflectionMethod->invoke($this->postgresqlLockService, 'exclusive'));
        static::assertNotSame($lockKeys, $reflectionMethod->invoke($this->postgresqlLockService, 'orders'));

        foreach ($lockKeys as $lockKey) {
            static::assertGreaterThanOrEqual(static::SIGNED_INT32_MINIMUM, $lockKey);
            static::assertLessThanOrEqual(static::SIGNED_INT32_MAXIMUM, $lockKey);
        }

        /* the high bit of the hash is set about half the time, so a negative half is the ordinary case, not a corner */
        static::assertLessThan(0, $lockKeys[1], 'the second half of `exclusive` wraps negative');
    }

    public function testToSignedInt32WrapsAtTheBoundary(): void
    {
        $reflectionMethod = new ReflectionMethod(PostgresqlLockService::class, 'toSignedInt32');

        static::assertSame(0, $reflectionMethod->invoke($this->postgresqlLockService, 0));
        static::assertSame(static::SIGNED_INT32_MAXIMUM, $reflectionMethod->invoke($this->postgresqlLockService, 0x7FFFFFFF));
        static::assertSame(static::SIGNED_INT32_MINIMUM, $reflectionMethod->invoke($this->postgresqlLockService, 0x80000000));
        static::assertSame(-1, $reflectionMethod->invoke($this->postgresqlLockService, 0xFFFFFFFF));
    }

    public function testIsDatabaseTrueAcceptsEveryDriverSpelling(): void
    {
        $reflectionMethod = new ReflectionMethod(PostgresqlLockService::class, 'isDatabaseTrue');

        foreach ([true, 1, '1', 't'] as $trueValue) {
            static::assertTrue($reflectionMethod->invoke($this->postgresqlLockService, $trueValue));
        }

        foreach ([false, 0, '0', 'f', null, 'true'] as $otherValue) {
            static::assertFalse($reflectionMethod->invoke($this->postgresqlLockService, $otherValue));
        }
    }

    public function testANegativeTimeoutIsRejectedBeforeAnyQuery(): void
    {
        $this->connection->shouldNotReceive('fetchOne');

        $this->expectException(PostgresqlLockException::class);
        $this->expectExceptionMessage('lock timeout must not be negative');
        $this->expectExceptionCode(0);

        $this->postgresqlLockService->acquire('orders', -1);
    }

    public function testTheServiceRefusesAConnectionOffPostgresql(): void
    {
        $this->connection->shouldReceive('getDatabasePlatform')
            ->andReturn(Mockery::mock(MySQLPlatform::class));
        $this->connection->shouldNotReceive('fetchOne');

        $this->expectException(PostgresqlLockException::class);
        $this->expectExceptionMessage('postgresql lock service requires a postgresql connection');

        $this->postgresqlLockService->hasLock('orders');
    }

    public function testAcquireTakesTheTwoKeyAdvisoryLockAndRegistersOneReference(): void
    {
        $this->mockPostgresqlPlatform();
        $this->connection->shouldReceive('fetchOne')
            ->with('SELECT pg_try_advisory_lock(?, ?)', Mockery::type('array'))
            ->once()
            ->andReturn('t');

        $this->postgresqlLockService->acquire('orders');

        static::assertSame(1, $this->readRegisteredLocks()['orders@@default']['count']);
    }

    public function testAcquireGivesUpOnceTheDeadlineHasPassed(): void
    {
        $this->mockPostgresqlPlatform();
        $this->connection->shouldReceive('fetchOne')
            ->with('SELECT pg_try_advisory_lock(?, ?)', Mockery::type('array'))
            ->once()
            ->andReturn('f');

        $this->expectException(PostgresqlLockException::class);
        $this->expectExceptionMessage('another operation with the same id is already in progress');

        $this->postgresqlLockService->acquire('orders');
    }

    public function testReleaseUnlocksWithTheSameTwoKeys(): void
    {
        $this->mockPostgresqlPlatform();
        $lockKeys = null;
        $this->connection->shouldReceive('fetchOne')
            ->with('SELECT pg_try_advisory_lock(?, ?)', Mockery::on(static function (array $keys) use (&$lockKeys): bool {
                $lockKeys = $keys;

                return true;
            }))
            ->once()
            ->andReturn(true);
        $this->connection->shouldReceive('fetchOne')
            ->with('SELECT pg_advisory_unlock(?, ?)', Mockery::on(static function (array $keys) use (&$lockKeys): bool {
                return $keys === $lockKeys;
            }))
            ->once()
            ->andReturn(true);

        $this->postgresqlLockService->acquire('orders');
        $this->postgresqlLockService->release('orders', throwException: true);

        static::assertSame([], $this->readRegisteredLocks());
    }

    public function testHasLockCountsGrantedLocksClusterWideAndInSessionByBackendPid(): void
    {
        $this->mockPostgresqlPlatform();
        $clusterWideQuery = "SELECT COUNT(*) FROM pg_locks WHERE locktype = 'advisory'"
            . ' AND classid = (?::int)::oid AND objid = (?::int)::oid AND objsubid = 2 AND granted = TRUE';
        $this->connection->shouldReceive('fetchOne')
            ->with($clusterWideQuery, Mockery::type('array'))
            ->twice()
            ->andReturn('1', '0');
        $this->connection->shouldReceive('fetchOne')
            ->with($clusterWideQuery . ' AND pid = pg_backend_pid()', Mockery::type('array'))
            ->once()
            ->andReturn('0');

        static::assertTrue($this->postgresqlLockService->hasLock('orders'));
        static::assertFalse($this->postgresqlLockService->hasLockInCurrentSession('orders'));
        static::assertFalse($this->postgresqlLockService->hasLock('orders'), 'a count of zero is not held');
    }

    public function testAnUnreadableLockCountIsAnError(): void
    {
        $this->mockPostgresqlPlatform();
        $this->connection->shouldReceive('fetchOne')
            ->once()
            ->andReturn('not a number');

        $this->expectException(PostgresqlLockException::class);
        $this->expectExceptionMessage('failed to check lock status');

        $this->postgresqlLockService->hasLock('orders');
    }

    protected function setUp(): void
    {
        $this->connection = Mockery::mock(Connection::class);

        $entityManager = Mockery::mock(EntityManager::class);
        $entityManager->shouldReceive('getConnection')
            ->andReturn($this->connection);

        $managerRegistry = Mockery::mock(ManagerRegistry::class);
        $managerRegistry->shouldReceive('getManager')
            ->andReturn($entityManager);

        $this->postgresqlLockService = new PostgresqlLockService($managerRegistry);
    }

    private function mockPostgresqlPlatform(): void
    {
        $this->connection->shouldReceive('getDatabasePlatform')
            ->andReturn(Mockery::mock(PostgreSQLPlatform::class));
    }

    /** @return array<string, array{count: int, lockName: string, entityManagerName: ?string}> */
    private function readRegisteredLocks(): array
    {
        /** @var array<string, array{count: int, lockName: string, entityManagerName: ?string}> $locks */
        $locks = (new ReflectionProperty(PostgresqlLockService::class, 'locks'))->getValue($this->postgresqlLockService);

        return $locks;
    }
}
