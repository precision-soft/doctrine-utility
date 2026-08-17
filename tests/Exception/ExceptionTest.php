<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Test\Exception;

use Exception as BaseException;
use PHPUnit\Framework\TestCase;
use PrecisionSoft\Doctrine\Utility\Contract\ExceptionInterface;
use PrecisionSoft\Doctrine\Utility\Exception\Exception;
use PrecisionSoft\Doctrine\Utility\Exception\MysqlLockException;

/**
 * @internal
 */
final class ExceptionTest extends TestCase
{
    public function testExceptionExtendsBaseException(): void
    {
        $exception = new Exception('test message');

        static::assertInstanceOf(BaseException::class, $exception);
        static::assertSame('test message', $exception->getMessage());
    }

    public function testExceptionWithCode(): void
    {
        $exception = new Exception('test', 42);

        static::assertSame(42, $exception->getCode());
    }

    public function testExceptionWithPreviousException(): void
    {
        $previous = new Exception('previous');
        $exception = new Exception('test', 0, $previous);

        static::assertSame($previous, $exception->getPrevious());
    }

    public function testMysqlLockExceptionExtendsException(): void
    {
        $exception = new MysqlLockException('lock error');

        static::assertInstanceOf(Exception::class, $exception);
        static::assertInstanceOf(BaseException::class, $exception);
        static::assertSame('lock error', $exception->getMessage());
    }

    public function testMysqlLockExceptionWithCodeAndPrevious(): void
    {
        $previous = new Exception('inner');
        $exception = new MysqlLockException('outer', 100, $previous);

        static::assertSame(100, $exception->getCode());
        static::assertSame($previous, $exception->getPrevious());
    }

    public function testExceptionImplementsExceptionInterface(): void
    {
        static::assertInstanceOf(ExceptionInterface::class, new Exception('test message'));
        static::assertInstanceOf(ExceptionInterface::class, new MysqlLockException('lock error'));
    }

    public function testContextDefaultsToAnEmptyArray(): void
    {
        static::assertSame([], (new Exception('test message'))->getContext());
        static::assertSame([], (new Exception('test message', 0, null, null))->getContext());
    }

    public function testContextIsReadBackFromTheConstructor(): void
    {
        $exception = new MysqlLockException('lock error', 0, null, ['lockName' => 'myLock', 'entityManagerName' => null]);

        static::assertSame(['lockName' => 'myLock', 'entityManagerName' => null], $exception->getContext());
    }

    public function testSetContextReplacesTheContextAndIsFluent(): void
    {
        $exception = new Exception('test message', 0, null, ['first' => 1]);

        static::assertSame($exception, $exception->setContext(['second' => 2]));
        static::assertSame(['second' => 2], $exception->getContext());

        $exception->setContext(null);

        static::assertSame([], $exception->getContext());
    }

    public function testTheContextDoesNotLeakIntoTheMessageCodeOrPrevious(): void
    {
        $previous = new BaseException('root cause');

        $exception = new Exception('test message', 7, $previous, ['key' => 'value']);

        static::assertSame('test message', $exception->getMessage());
        static::assertSame(7, $exception->getCode());
        static::assertSame($previous, $exception->getPrevious());
    }

    public function testTheConstructorDefaultsToAnEmptyMessageZeroCodeAndNoPrevious(): void
    {
        $exception = new Exception();

        static::assertSame('', $exception->getMessage());
        static::assertSame(0, $exception->getCode());
        static::assertNull($exception->getPrevious());
        static::assertSame([], $exception->getContext());
    }
}
