<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Test\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManager;
use Doctrine\Persistence\ManagerRegistry;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use PrecisionSoft\Doctrine\Utility\Exception\MysqlLockException;
use PrecisionSoft\Doctrine\Utility\Service\MysqlLockService;

/**
 * @internal
 */
final class MysqlLockServiceTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private ManagerRegistry|MockInterface $managerRegistry;
    private EntityManager|MockInterface $entityManager;
    private Connection|MockInterface $connection;
    private MysqlLockService $mysqlLockService;

    protected function setUp(): void
    {
        $this->connection = Mockery::mock(Connection::class);
        $this->entityManager = Mockery::mock(EntityManager::class);
        $this->entityManager->shouldReceive('getConnection')
            ->andReturn($this->connection);
        $this->managerRegistry = Mockery::mock(ManagerRegistry::class);
        $this->managerRegistry->shouldReceive('getManager')
            ->byDefault()
            ->andReturn($this->entityManager);
        $this->mysqlLockService = new MysqlLockService($this->managerRegistry);
    }

    public function testIsLockedReturnsFalseWhenLockIsFree(): void
    {
        $queryResult = Mockery::mock(Result::class);
        $queryResult->shouldReceive('fetchAssociative')
            ->once()
            ->andReturn(['lockIsFree' => 1]);

        $this->connection->shouldReceive('quote')
            ->andReturn("'test_lock'");
        $this->connection->shouldReceive('executeQuery')
            ->once()
            ->andReturn($queryResult);

        static::assertSame(false, $this->mysqlLockService->hasLock('test_lock'));
    }

    public function testIsLockedReturnsTrueWhenLockIsNotFree(): void
    {
        $queryResult = Mockery::mock(Result::class);
        $queryResult->shouldReceive('fetchAssociative')
            ->once()
            ->andReturn(['lockIsFree' => 0]);

        $this->connection->shouldReceive('quote')
            ->andReturn("'test_lock'");
        $this->connection->shouldReceive('executeQuery')
            ->once()
            ->andReturn($queryResult);

        static::assertSame(true, $this->mysqlLockService->hasLock('test_lock'));
    }

    public function testIsLockedThrowsExceptionOnFalseRow(): void
    {
        $queryResult = Mockery::mock(Result::class);
        $queryResult->shouldReceive('fetchAssociative')
            ->once()
            ->andReturn(false);

        $this->connection->shouldReceive('quote')
            ->andReturn("'test_lock'");
        $this->connection->shouldReceive('executeQuery')
            ->once()
            ->andReturn($queryResult);

        $this->expectException(MysqlLockException::class);
        $this->expectExceptionMessage('failed to check lock status');

        $this->mysqlLockService->hasLock('test_lock');
    }

    public function testIsLockedThrowsExceptionOnMissingKey(): void
    {
        $queryResult = Mockery::mock(Result::class);
        $queryResult->shouldReceive('fetchAssociative')
            ->once()
            ->andReturn(['otherKey' => 1]);

        $this->connection->shouldReceive('quote')
            ->andReturn("'test_lock'");
        $this->connection->shouldReceive('executeQuery')
            ->once()
            ->andReturn($queryResult);

        $this->expectException(MysqlLockException::class);
        $this->expectExceptionMessage('failed to check lock status');

        $this->mysqlLockService->hasLock('test_lock');
    }

    public function testIsLockedWithEntityManagerName(): void
    {
        $this->managerRegistry->shouldReceive('getManager')
            ->with('custom')
            ->andReturn($this->entityManager);

        $queryResult = Mockery::mock(Result::class);
        $queryResult->shouldReceive('fetchAssociative')
            ->once()
            ->andReturn(['lockIsFree' => 1]);

        $this->connection->shouldReceive('quote')
            ->andReturn("'test_lock'");
        $this->connection->shouldReceive('executeQuery')
            ->once()
            ->andReturn($queryResult);

        static::assertSame(false, $this->mysqlLockService->hasLock('test_lock', 'custom'));
    }

    public function testAcquireSuccessfully(): void
    {
        $queryResult = Mockery::mock(Result::class);
        $queryResult->shouldReceive('fetchAssociative')
            ->once()
            ->andReturn(['lockAcquired' => 1]);

        $this->connection->shouldReceive('quote')
            ->andReturn("'test_lock'");
        $this->connection->shouldReceive('executeQuery')
            ->once()
            ->andReturn($queryResult);

        $returnValue = $this->mysqlLockService->acquire('test_lock');

        static::assertSame($this->mysqlLockService, $returnValue);
    }

    public function testAcquireWithTimeout(): void
    {
        $queryResult = Mockery::mock(Result::class);
        $queryResult->shouldReceive('fetchAssociative')
            ->once()
            ->andReturn(['lockAcquired' => 1]);

        $this->connection->shouldReceive('quote')
            ->andReturn("'test_lock'");
        $this->connection->shouldReceive('executeQuery')
            ->once()
            ->andReturn($queryResult);

        $returnValue = $this->mysqlLockService->acquire('test_lock', 10);

        static::assertSame($this->mysqlLockService, $returnValue);
    }

    public function testAcquireIncrementsCountForExistingLock(): void
    {
        $queryResult = Mockery::mock(Result::class);
        $queryResult->shouldReceive('fetchAssociative')
            ->once()
            ->andReturn(['lockAcquired' => 1]);

        $this->connection->shouldReceive('quote')
            ->andReturn("'test_lock'");
        $this->connection->shouldReceive('executeQuery')
            ->once()
            ->andReturn($queryResult);

        $this->mysqlLockService->acquire('test_lock');

        $returnValue = $this->mysqlLockService->acquire('test_lock');
        static::assertSame($this->mysqlLockService, $returnValue);
    }

    public function testAcquireWithForceRefreshReacquiresLock(): void
    {
        $acquireResult = Mockery::mock(Result::class);
        $acquireResult->shouldReceive('fetchAssociative')
            ->once()
            ->andReturn(['lockAcquired' => 1]);

        $reacquireResult = Mockery::mock(Result::class);
        $reacquireResult->shouldReceive('fetchAssociative')
            ->once()
            ->andReturn(['lockAcquired' => 1]);

        $this->connection->shouldReceive('quote')
            ->andReturn("'test_lock'");
        $this->connection->shouldReceive('executeQuery')
            ->twice()
            ->andReturn($acquireResult, $reacquireResult);

        $this->mysqlLockService->acquire('test_lock');
        $this->mysqlLockService->acquire('test_lock', 0, null, true);
    }

    public function testAcquireThrowsExceptionOnTimeout(): void
    {
        $queryResult = Mockery::mock(Result::class);
        $queryResult->shouldReceive('fetchAssociative')
            ->once()
            ->andReturn(['lockAcquired' => 0]);

        $this->connection->shouldReceive('quote')
            ->andReturn("'test_lock'");
        $this->connection->shouldReceive('executeQuery')
            ->once()
            ->andReturn($queryResult);

        $this->expectException(MysqlLockException::class);
        $this->expectExceptionMessage('another operation with the same id is already in progress');

        $this->mysqlLockService->acquire('test_lock');
    }

    public function testAcquireThrowsExceptionOnError(): void
    {
        $queryResult = Mockery::mock(Result::class);
        $queryResult->shouldReceive('fetchAssociative')
            ->once()
            ->andReturn(['lockAcquired' => null]);

        $this->connection->shouldReceive('quote')
            ->andReturn("'test_lock'");
        $this->connection->shouldReceive('executeQuery')
            ->once()
            ->andReturn($queryResult);

        $this->expectException(MysqlLockException::class);
        $this->expectExceptionMessage('failed to acquire lock: unexpected response');

        $this->mysqlLockService->acquire('test_lock');
    }

    public function testAcquireThrowsExceptionOnInvalidResponse(): void
    {
        $queryResult = Mockery::mock(Result::class);
        $queryResult->shouldReceive('fetchAssociative')
            ->once()
            ->andReturn(false);

        $this->connection->shouldReceive('quote')
            ->andReturn("'test_lock'");
        $this->connection->shouldReceive('executeQuery')
            ->once()
            ->andReturn($queryResult);

        $this->expectException(MysqlLockException::class);
        $this->expectExceptionMessage('failed to acquire lock: invalid response');

        $this->mysqlLockService->acquire('test_lock');
    }

    public function testReleaseSuccessfully(): void
    {
        $acquireResult = Mockery::mock(Result::class);
        $acquireResult->shouldReceive('fetchAssociative')
            ->once()
            ->andReturn(['lockAcquired' => 1]);

        $releaseResult = Mockery::mock(Result::class);
        $releaseResult->shouldReceive('fetchAssociative')
            ->once()
            ->andReturn(['lockReleased' => 1]);

        $this->connection->shouldReceive('quote')
            ->andReturn("'test_lock'");
        $this->connection->shouldReceive('executeQuery')
            ->twice()
            ->andReturn($acquireResult, $releaseResult);

        $this->mysqlLockService->acquire('test_lock');
        $returnValue = $this->mysqlLockService->release('test_lock');

        static::assertSame($this->mysqlLockService, $returnValue);
    }

    public function testReleaseDecrementsCountWithoutReleasingDbLock(): void
    {
        $acquireResult = Mockery::mock(Result::class);
        $acquireResult->shouldReceive('fetchAssociative')
            ->once()
            ->andReturn(['lockAcquired' => 1]);

        $this->connection->shouldReceive('quote')
            ->andReturn("'test_lock'");
        $this->connection->shouldReceive('executeQuery')
            ->once()
            ->andReturn($acquireResult);

        $this->mysqlLockService->acquire('test_lock');
        $this->mysqlLockService->acquire('test_lock');

        $returnValue = $this->mysqlLockService->release('test_lock');
        static::assertSame($this->mysqlLockService, $returnValue);
    }

    public function testReleaseNonExistentLockSilently(): void
    {
        $returnValue = $this->mysqlLockService->release('non_existent');

        static::assertSame($this->mysqlLockService, $returnValue);
    }

    public function testReleaseNonExistentLockThrowsWhenRequested(): void
    {
        $this->expectException(MysqlLockException::class);
        $this->expectExceptionMessage('the lock "non_existent" is not currently acquired');

        $this->mysqlLockService->release('non_existent', null, true);
    }

    public function testReleaseThrowsOnNotEstablishedByThread(): void
    {
        $acquireResult = Mockery::mock(Result::class);
        $acquireResult->shouldReceive('fetchAssociative')
            ->once()
            ->andReturn(['lockAcquired' => 1]);

        $releaseResult = Mockery::mock(Result::class);
        $releaseResult->shouldReceive('fetchAssociative')
            ->once()
            ->andReturn(['lockReleased' => 0]);

        $this->connection->shouldReceive('quote')
            ->andReturn("'test_lock'");
        $this->connection->shouldReceive('executeQuery')
            ->twice()
            ->andReturn($acquireResult, $releaseResult);

        $this->mysqlLockService->acquire('test_lock');

        $this->expectException(MysqlLockException::class);
        $this->expectExceptionMessage('lock was not established by this thread');

        $this->mysqlLockService->release('test_lock', null, true);
    }

    public function testReleaseThrowsOnLockNotExist(): void
    {
        $acquireResult = Mockery::mock(Result::class);
        $acquireResult->shouldReceive('fetchAssociative')
            ->once()
            ->andReturn(['lockAcquired' => 1]);

        $releaseResult = Mockery::mock(Result::class);
        $releaseResult->shouldReceive('fetchAssociative')
            ->once()
            ->andReturn(['lockReleased' => null]);

        $this->connection->shouldReceive('quote')
            ->andReturn("'test_lock'");
        $this->connection->shouldReceive('executeQuery')
            ->twice()
            ->andReturn($acquireResult, $releaseResult);

        $this->mysqlLockService->acquire('test_lock');

        $this->expectException(MysqlLockException::class);
        $this->expectExceptionMessage('failed to release lock: invalid response');

        $this->mysqlLockService->release('test_lock', null, true);
    }

    public function testReleaseInvalidResponseRow(): void
    {
        $acquireResult = Mockery::mock(Result::class);
        $acquireResult->shouldReceive('fetchAssociative')
            ->once()
            ->andReturn(['lockAcquired' => 1]);

        $releaseResult = Mockery::mock(Result::class);
        $releaseResult->shouldReceive('fetchAssociative')
            ->once()
            ->andReturn(false);

        $this->connection->shouldReceive('quote')
            ->andReturn("'test_lock'");
        $this->connection->shouldReceive('executeQuery')
            ->twice()
            ->andReturn($acquireResult, $releaseResult);

        $this->mysqlLockService->acquire('test_lock');

        $this->expectException(MysqlLockException::class);
        $this->expectExceptionMessage('failed to release lock: invalid response');

        $this->mysqlLockService->release('test_lock', null, true);
    }

    public function testAcquireLocksSuccessfully(): void
    {
        $queryResult = Mockery::mock(Result::class);
        $queryResult->shouldReceive('fetchAssociative')
            ->andReturn(['lockAcquired' => 1]);

        $this->connection->shouldReceive('quote')
            ->andReturn("'lock_a'", "'lock_b'");
        $this->connection->shouldReceive('executeQuery')
            ->twice()
            ->andReturn($queryResult);

        $returnValue = $this->mysqlLockService->acquireLocks(['lock_b', 'lock_a']);

        static::assertSame($this->mysqlLockService, $returnValue);
    }

    public function testAcquireLocksReleasesOnFailure(): void
    {
        $successResult = Mockery::mock(Result::class);
        $successResult->shouldReceive('fetchAssociative')
            ->andReturn(['lockAcquired' => 1]);

        $failResult = Mockery::mock(Result::class);
        $failResult->shouldReceive('fetchAssociative')
            ->andReturn(['lockAcquired' => 0]);

        $this->connection->shouldReceive('quote')
            ->andReturn("'lock_a'", "'lock_b'");
        $this->connection->shouldReceive('executeQuery')
            ->andReturn($successResult, $failResult);

        $this->expectException(MysqlLockException::class);

        $this->mysqlLockService->acquireLocks(['lock_a', 'lock_b']);
    }

    public function testReleaseLocksWithSpecificNames(): void
    {
        $acquireResult = Mockery::mock(Result::class);
        $acquireResult->shouldReceive('fetchAssociative')
            ->andReturn(['lockAcquired' => 1]);

        $releaseResult = Mockery::mock(Result::class);
        $releaseResult->shouldReceive('fetchAssociative')
            ->andReturn(['lockReleased' => 1]);

        $this->connection->shouldReceive('quote')
            ->andReturn("'lock_a'", "'lock_b'", "'lock_a'", "'lock_b'");
        $this->connection->shouldReceive('executeQuery')
            ->andReturn($acquireResult, $acquireResult, $releaseResult, $releaseResult);

        $this->mysqlLockService->acquire('lock_a');
        $this->mysqlLockService->acquire('lock_b');

        $returnValue = $this->mysqlLockService->releaseLocks(['lock_a', 'lock_b']);

        static::assertSame($this->mysqlLockService, $returnValue);
    }

    public function testReleaseLocksWithNullReleasesAll(): void
    {
        $acquireResult = Mockery::mock(Result::class);
        $acquireResult->shouldReceive('fetchAssociative')
            ->andReturn(['lockAcquired' => 1]);

        $releaseResult = Mockery::mock(Result::class);
        $releaseResult->shouldReceive('fetchAssociative')
            ->andReturn(['lockReleased' => 1]);

        $this->connection->shouldReceive('quote')
            ->andReturn("'lock_a'", "'lock_a'");
        $this->connection->shouldReceive('executeQuery')
            ->andReturn($acquireResult, $releaseResult);

        $this->mysqlLockService->acquire('lock_a');

        $returnValue = $this->mysqlLockService->releaseLocks();

        static::assertSame($this->mysqlLockService, $returnValue);
    }

    public function testReleaseLocksSwallowsExceptionsWhenThrowFalse(): void
    {
        $returnValue = $this->mysqlLockService->releaseLocks(['nonexistent_lock']);

        static::assertSame($this->mysqlLockService, $returnValue);
    }

    public function testReleaseLocksThrowsForNonexistentLockWhenThrowTrue(): void
    {
        $this->expectException(MysqlLockException::class);

        $this->mysqlLockService->releaseLocks(['nonexistent_lock'], null, true);
    }

    public function testPrepareLockNameTruncatesLongNames(): void
    {
        $longName = \str_repeat('a', 65);

        $queryResult = Mockery::mock(Result::class);
        $queryResult->shouldReceive('fetchAssociative')
            ->andReturn(['lockIsFree' => 1]);

        $this->connection->shouldReceive('quote')
            ->once()
            ->andReturnUsing(function (string $lockName) use ($longName) {
                $expectedName = \substr($longName, 0, 10) . '>>' . \md5($longName) . '<<' . \substr($longName, -10);
                static::assertSame($expectedName, $lockName);

                return "'" . $lockName . "'";
            });

        $this->connection->shouldReceive('executeQuery')
            ->once()
            ->andReturn($queryResult);

        $this->mysqlLockService->hasLock($longName);
    }

    public function testPrepareLockNameKeepsShortNames(): void
    {
        $shortName = 'short_lock';

        $queryResult = Mockery::mock(Result::class);
        $queryResult->shouldReceive('fetchAssociative')
            ->andReturn(['lockIsFree' => 1]);

        $this->connection->shouldReceive('quote')
            ->once()
            ->with($shortName)
            ->andReturn("'" . $shortName . "'");

        $this->connection->shouldReceive('executeQuery')
            ->once()
            ->andReturn($queryResult);

        $this->mysqlLockService->hasLock($shortName);
    }

    public function testPrepareLockNameExactly64CharsNotTruncated(): void
    {
        $maxLengthName = \str_repeat('x', 64);

        $queryResult = Mockery::mock(Result::class);
        $queryResult->shouldReceive('fetchAssociative')
            ->andReturn(['lockIsFree' => 1]);

        $this->connection->shouldReceive('quote')
            ->once()
            ->with($maxLengthName)
            ->andReturn("'" . $maxLengthName . "'");

        $this->connection->shouldReceive('executeQuery')
            ->once()
            ->andReturn($queryResult);

        $this->mysqlLockService->hasLock($maxLengthName);
    }
}
