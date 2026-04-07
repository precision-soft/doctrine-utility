<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Test\Exception;

use Exception as BaseException;
use PHPUnit\Framework\TestCase;
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
}
