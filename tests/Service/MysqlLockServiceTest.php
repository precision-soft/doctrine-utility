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
use Doctrine\Persistence\ObjectManager;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use PrecisionSoft\Doctrine\Utility\Exception\MysqlLockException;
use PrecisionSoft\Doctrine\Utility\Service\MysqlLockService;
use PrecisionSoft\Doctrine\Utility\Test\Utility\Exception\FixtureException;
use ReflectionMethod;
use ReflectionProperty;

/**
 * @internal
 */
final class MysqlLockServiceTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private ManagerRegistry&MockInterface $managerRegistry;
    private EntityManager&MockInterface $entityManager;
    private Connection&MockInterface $connection;
    private MysqlLockService $mysqlLockService;

    public function testHasLockReturnsFalseWhenLockIsFree(): void
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

    public function testHasLockReturnsTrueWhenLockIsNotFree(): void
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

    public function testHasLockThrowsExceptionOnFalseRow(): void
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

    public function testHasLockThrowsExceptionOnMissingKey(): void
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

    public function testHasLockWithEntityManagerName(): void
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

    public function testAcquireWithForceRefreshKeepsALockTheSessionStillOwns(): void
    {
        $acquireResult = Mockery::mock(Result::class);
        $acquireResult->shouldReceive('fetchAssociative')
            ->once()
            ->andReturn(['lockAcquired' => 1]);

        $ownershipResult = Mockery::mock(Result::class);
        $ownershipResult->shouldReceive('fetchAssociative')
            ->once()
            ->andReturn(['lockOwner' => 42, 'sessionId' => 42]);

        $this->connection->shouldReceive('quote')
            ->andReturn("'test_lock'");
        $this->connection->shouldReceive('executeQuery')
            ->twice()
            ->andReturn($acquireResult, $ownershipResult);

        $this->mysqlLockService->acquire('test_lock');
        $this->mysqlLockService->acquire('test_lock', 0, null, true);
    }

    public function testAcquireWithForceRefreshRetakesALockTheSessionLost(): void
    {
        $acquireResult = Mockery::mock(Result::class);
        $acquireResult->shouldReceive('fetchAssociative')
            ->once()
            ->andReturn(['lockAcquired' => 1]);

        $ownershipResult = Mockery::mock(Result::class);
        $ownershipResult->shouldReceive('fetchAssociative')
            ->once()
            ->andReturn(['lockOwner' => null, 'sessionId' => 42]);

        $reacquireResult = Mockery::mock(Result::class);
        $reacquireResult->shouldReceive('fetchAssociative')
            ->once()
            ->andReturn(['lockAcquired' => 1]);

        $this->connection->shouldReceive('quote')
            ->andReturn("'test_lock'");
        $this->connection->shouldReceive('executeQuery')
            ->times(3)
            ->andReturn($acquireResult, $ownershipResult, $reacquireResult);

        $this->mysqlLockService->acquire('test_lock');
        $this->mysqlLockService->acquire('test_lock', 0, null, true);
    }

    public function testAcquireWithForceRefreshDoesNotInflateCount(): void
    {
        $acquireResult = Mockery::mock(Result::class);
        $acquireResult->shouldReceive('fetchAssociative')
            ->once()
            ->andReturn(['lockAcquired' => 1]);

        $ownershipResult = Mockery::mock(Result::class);
        $ownershipResult->shouldReceive('fetchAssociative')
            ->once()
            ->andReturn(['lockOwner' => 42, 'sessionId' => 42]);

        $releaseResult = Mockery::mock(Result::class);
        $releaseResult->shouldReceive('fetchAssociative')
            ->once()
            ->andReturn(['lockReleased' => 1]);

        $this->connection->shouldReceive('quote')
            ->andReturn("'test_lock'");
        $this->connection->shouldReceive('executeQuery')
            ->times(3)
            ->andReturn($acquireResult, $ownershipResult, $releaseResult);

        $this->mysqlLockService->acquire('test_lock');
        $this->mysqlLockService->acquire('test_lock', 0, null, true);

        $this->mysqlLockService->release('test_lock', null, true);
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

    public function testReleaseThrowsOnNotEstablishedBySession(): void
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
        $this->expectExceptionMessage('lock was not established by this session');

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

    public function testReleaseLocksWithNullFullyReleasesReentrantLock(): void
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
            ->andReturn("'lock_a'");
        $this->connection->shouldReceive('executeQuery')
            ->times(2)
            ->andReturn($acquireResult, $releaseResult);

        $this->mysqlLockService->acquire('lock_a');
        $this->mysqlLockService->acquire('lock_a');

        $this->mysqlLockService->releaseLocks();

        $this->expectException(MysqlLockException::class);
        $this->mysqlLockService->release('lock_a', null, true);
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

    /* caught rather than expectException(): the assertions are on the exception, which ends the test at the throw */
    public function testReleaseLocksNamesTheFailingLockInTheExceptionContext(): void
    {
        try {
            $this->mysqlLockService->releaseLocks(['nonexistent_lock'], 'secondary', true);

            static::fail('releaseLocks was expected to throw');
        } catch (MysqlLockException $mysqlLockException) {
            static::assertSame(
                [
                    'lockName' => 'nonexistent_lock',
                    'entityManagerName' => 'secondary',
                    'releasedAll' => false,
                ],
                $mysqlLockException->getContext(),
            );

            static::assertSame('the lock "nonexistent_lock" is not currently acquired', $mysqlLockException->getMessage());
        }
    }

    public function testReleaseAllLocksNamesTheFailingLockInTheExceptionContext(): void
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
            ->andReturn("'held_lock'");
        $this->connection->shouldReceive('executeQuery')
            ->twice()
            ->andReturn($acquireResult, $releaseResult);

        $this->mysqlLockService->acquire('held_lock');

        try {
            $this->mysqlLockService->releaseLocks(null, null, true);

            static::fail('releaseLocks was expected to throw');
        } catch (MysqlLockException $mysqlLockException) {
            static::assertSame(
                [
                    'lockName' => 'held_lock',
                    'entityManagerName' => null,
                    'releasedAll' => true,
                ],
                $mysqlLockException->getContext(),
            );
        }
    }

    public function testHasLockInCurrentSessionComparesTheOwnerAgainstThisConnection(): void
    {
        $ownershipResult = Mockery::mock(Result::class);
        $ownershipResult->shouldReceive('fetchAssociative')
            ->once()
            ->andReturn(['lockOwner' => 42, 'sessionId' => 42]);

        $this->connection->shouldReceive('quote')
            ->andReturn("'test_lock'");
        $this->connection->shouldReceive('executeQuery')
            ->once()
            ->andReturn($ownershipResult);

        static::assertTrue($this->mysqlLockService->hasLockInCurrentSession('test_lock'));
    }

    public function testHasLockInCurrentSessionIsFalseForALockHeldByAnotherConnection(): void
    {
        $ownershipResult = Mockery::mock(Result::class);
        $ownershipResult->shouldReceive('fetchAssociative')
            ->once()
            ->andReturn(['lockOwner' => 7, 'sessionId' => 42]);

        $this->connection->shouldReceive('quote')
            ->andReturn("'test_lock'");
        $this->connection->shouldReceive('executeQuery')
            ->once()
            ->andReturn($ownershipResult);

        static::assertFalse($this->mysqlLockService->hasLockInCurrentSession('test_lock'));
    }

    public function testHasLockInCurrentSessionIsFalseForAFreeLock(): void
    {
        $ownershipResult = Mockery::mock(Result::class);
        $ownershipResult->shouldReceive('fetchAssociative')
            ->once()
            ->andReturn(['lockOwner' => null, 'sessionId' => 42]);

        $this->connection->shouldReceive('quote')
            ->andReturn("'test_lock'");
        $this->connection->shouldReceive('executeQuery')
            ->once()
            ->andReturn($ownershipResult);

        static::assertFalse($this->mysqlLockService->hasLockInCurrentSession('test_lock'));
    }

    public function testHasLockInCurrentSessionThrowsWhenTheOwnerColumnIsMissing(): void
    {
        $ownershipResult = Mockery::mock(Result::class);
        $ownershipResult->shouldReceive('fetchAssociative')
            ->once()
            ->andReturn(['sessionId' => 42]);

        $this->connection->shouldReceive('quote')
            ->andReturn("'test_lock'");
        $this->connection->shouldReceive('executeQuery')
            ->once()
            ->andReturn($ownershipResult);

        $this->expectException(MysqlLockException::class);
        $this->expectExceptionMessage('failed to check lock ownership');

        $this->mysqlLockService->hasLockInCurrentSession('test_lock');
    }

    public function testAReleaseTheEngineNeverAnsweredKeepsTheLockSoTheCallerCanRetry(): void
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
            ->times(3)
            ->andReturnUsing(
                static fn(): Result => $acquireResult,
                static fn(): Result => throw new FixtureException('the server went away'),
                static fn(): Result => $releaseResult,
            );

        $this->mysqlLockService->acquire('test_lock');
        $this->mysqlLockService->release('test_lock');

        /* the retry reaches the engine, which it could not if the failed release had dropped the bookkeeping */
        $this->mysqlLockService->release('test_lock', null, true);

        $this->expectException(MysqlLockException::class);
        $this->expectExceptionMessage('the lock "test_lock" is not currently acquired');

        $this->mysqlLockService->release('test_lock', null, true);
    }

    public function testAReleaseTheServerRefusedForgetsTheLock(): void
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
            ->times(2)
            ->andReturn($acquireResult, $releaseResult);

        $this->mysqlLockService->acquire('test_lock');
        $this->mysqlLockService->release('test_lock');

        $this->expectException(MysqlLockException::class);
        $this->expectExceptionMessage('the lock "test_lock" is not currently acquired');

        $this->mysqlLockService->release('test_lock', null, true);
    }

    public function testReleaseLocksDoesNotSpinWhenTheEngineKeepsRefusingToAnswer(): void
    {
        $acquireResult = Mockery::mock(Result::class);
        $acquireResult->shouldReceive('fetchAssociative')
            ->once()
            ->andReturn(['lockAcquired' => 1]);

        $this->connection->shouldReceive('quote')
            ->andReturn("'test_lock'");
        $this->connection->shouldReceive('executeQuery')
            ->times(2)
            ->andReturnUsing(
                static fn(): Result => $acquireResult,
                static fn(): Result => throw new FixtureException('the server went away'),
            );

        $this->mysqlLockService->acquire('test_lock');

        /* one attempt, then out: the drain loop would otherwise retry a release that keeps its own bookkeeping for ever */
        $this->mysqlLockService->releaseLocks();

        static::assertArrayHasKey('test_lock@@default', $this->readRegisteredLocks());
    }

    public function testAcquireLocksTakesTheNamesInSortedOrder(): void
    {
        $executedQueries = [];

        $this->connection->shouldReceive('quote')
            ->andReturnUsing(static fn(string $lockName): string => "'" . $lockName . "'");
        $this->connection->shouldReceive('executeQuery')
            ->times(2)
            ->andReturnUsing(function (string $query) use (&$executedQueries) {
                $executedQueries[] = $query;

                $result = Mockery::mock(Result::class);
                $result->shouldReceive('fetchAssociative')
                    ->andReturn(['lockAcquired' => 1]);

                return $result;
            });

        $this->mysqlLockService->acquireLocks(['zulu', 'alpha']);

        static::assertSame(
            ["SELECT GET_LOCK('alpha', 0) AS lockAcquired", "SELECT GET_LOCK('zulu', 0) AS lockAcquired"],
            $executedQueries,
            'a stable order is what keeps two callers from deadlocking against each other',
        );
    }

    public function testANegativeTimeoutIsRejectedBeforeAnyQuery(): void
    {
        /* GET_LOCK(name, -1) waits forever on mysql and mariadb; the postgresql service already refuses it, one contract must */
        $this->connection->shouldNotReceive('executeQuery');

        $this->expectException(MysqlLockException::class);
        $this->expectExceptionMessage('lock timeout must not be negative');

        $this->mysqlLockService->acquire('test_lock', -1);
    }

    public function testAcquireLocksSortsTheNamesAsStrings(): void
    {
        $executedQueries = [];

        $this->connection->shouldReceive('quote')
            ->andReturnUsing(static fn(string $lockName): string => "'" . $lockName . "'");
        $this->connection->shouldReceive('executeQuery')
            ->times(4)
            ->andReturnUsing(function (string $query) use (&$executedQueries) {
                $executedQueries[] = $query;

                $result = Mockery::mock(Result::class);
                $result->shouldReceive('fetchAssociative')
                    ->andReturn(['lockAcquired' => 1]);

                return $result;
            });

        $this->mysqlLockService->acquireLocks(['b', '10', '9', 'a']);

        /* the default comparison reads `10` and `9` as numbers and a mix of numeric and plain names is not a total order, so two callers could still sort the same names differently */
        static::assertSame(
            [
                "SELECT GET_LOCK('10', 0) AS lockAcquired",
                "SELECT GET_LOCK('9', 0) AS lockAcquired",
                "SELECT GET_LOCK('a', 0) AS lockAcquired",
                "SELECT GET_LOCK('b', 0) AS lockAcquired",
            ],
            $executedQueries,
        );
    }

    public function testAcquireLocksRollsBackInReverseOrderWhenOneFails(): void
    {
        $executedQueries = [];

        $this->connection->shouldReceive('quote')
            ->andReturnUsing(static fn(string $lockName): string => "'" . $lockName . "'");
        $this->connection->shouldReceive('executeQuery')
            ->andReturnUsing(function (string $query) use (&$executedQueries) {
                $executedQueries[] = $query;

                $result = Mockery::mock(Result::class);
                $result->shouldReceive('fetchAssociative')
                    ->andReturn(
                        true === \str_contains($query, "GET_LOCK('charlie'")
                            ? ['lockAcquired' => 0]
                            : ['lockAcquired' => 1, 'lockReleased' => 1],
                    );

                return $result;
            });

        try {
            $this->mysqlLockService->acquireLocks(['charlie', 'bravo', 'alpha']);
            static::fail('the contended lock was reported as acquired');
        } catch (MysqlLockException $mysqlLockException) {
            static::assertSame(
                [
                    "SELECT GET_LOCK('alpha', 0) AS lockAcquired",
                    "SELECT GET_LOCK('bravo', 0) AS lockAcquired",
                    "SELECT GET_LOCK('charlie', 0) AS lockAcquired",
                    "SELECT RELEASE_LOCK('bravo') AS lockReleased",
                    "SELECT RELEASE_LOCK('alpha') AS lockReleased",
                ],
                $executedQueries,
                'only the locks actually taken are released, and in the reverse order they were taken',
            );
        }
    }

    public function testWrapExceptionKeepsALockExceptionAndPrefixesAnyOther(): void
    {
        $this->connection->shouldReceive('quote')
            ->andReturn("'test_lock'");
        $this->connection->shouldReceive('executeQuery')
            ->once()
            ->andThrow(new FixtureException('the server went away'));

        $this->expectException(MysqlLockException::class);
        $this->expectExceptionMessage('failed acquiring lock `test_lock`: `the server went away`');

        $this->mysqlLockService->acquire('test_lock');
    }

    public function testGetEntityManagerRejectsAManagerThatIsNotAnEntityManager(): void
    {
        $managerRegistryMock = Mockery::mock(ManagerRegistry::class);
        $managerRegistryMock->shouldReceive('getManager')
            ->andReturn(Mockery::mock(ObjectManager::class));

        $this->expectException(MysqlLockException::class);
        $this->expectExceptionMessage('manager "other" is not an instance of entity manager');

        (new MysqlLockService($managerRegistryMock))->hasLock('test_lock', 'other');
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

    public function testLockKeySeparatesBothTheLockNameAndTheEntityManager(): void
    {
        $reflectionMethod = new ReflectionMethod(MysqlLockService::class, 'buildLockKey');

        $keys = [
            $reflectionMethod->invoke($this->mysqlLockService, 'alpha', null),
            $reflectionMethod->invoke($this->mysqlLockService, 'beta', null),
            $reflectionMethod->invoke($this->mysqlLockService, 'alpha', 'secondary'),
            $reflectionMethod->invoke($this->mysqlLockService, 'beta', 'secondary'),
        ];

        static::assertCount(4, \array_unique($keys));

        static::assertSame(
            $reflectionMethod->invoke($this->mysqlLockService, 'alpha', null),
            $reflectionMethod->invoke($this->mysqlLockService, 'alpha', 'default'),
        );
    }

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

    /** @return array<string, array{count: int, lockName: string, entityManagerName: ?string}> */
    private function readRegisteredLocks(): array
    {
        /** @var array<string, array{count: int, lockName: string, entityManagerName: ?string}> $locks */
        $locks = (new ReflectionProperty(MysqlLockService::class, 'locks'))->getValue($this->mysqlLockService);

        return $locks;
    }
}
