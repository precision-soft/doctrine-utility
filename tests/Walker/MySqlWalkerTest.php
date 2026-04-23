<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Test\Walker;

use PHPUnit\Framework\TestCase;
use PrecisionSoft\Doctrine\Utility\Exception\Exception;
use PrecisionSoft\Doctrine\Utility\Walker\MySqlWalker;
use ReflectionClass;
use ReflectionMethod;

/**
 * @internal
 */
final class MySqlWalkerTest extends TestCase
{
    public function testHintConstants(): void
    {
        static::assertSame('MySqlWalker.UseIndex', MySqlWalker::HINT_USE_INDEX);
        static::assertSame('MySqlWalker.IgnoreIndex', MySqlWalker::HINT_IGNORE_INDEX);
        static::assertSame('MySqlWalker.ForceIndex', MySqlWalker::HINT_FORCE_INDEX);
        static::assertSame('MySqlWalker.SelectForUpdate', MySqlWalker::HINT_SELECT_FOR_UPDATE);
        static::assertSame('MySqlWalker.IgnoreIndexOnJoin', MySqlWalker::HINT_IGNORE_INDEX_ON_JOIN);
    }

    public function testValidateIdentifierWithValidSingleIndex(): void
    {
        $this->callValidateIdentifier('PRIMARY');
        $this->addToAssertionCount(1);
    }

    public function testValidateIdentifierWithValidMultipleIndexes(): void
    {
        $this->callValidateIdentifier('PRIMARY, other_index');
        $this->addToAssertionCount(1);
    }

    public function testValidateIdentifierWithUnderscore(): void
    {
        $this->callValidateIdentifier('_my_index');
        $this->addToAssertionCount(1);
    }

    public function testValidateIdentifierWithAlphanumeric(): void
    {
        $this->callValidateIdentifier('idx_user_123');
        $this->addToAssertionCount(1);
    }

    public function testValidateIdentifierThrowsOnInvalidCharacters(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('invalid identifier');

        $this->callValidateIdentifier('DROP TABLE users; --');
    }

    public function testValidateIdentifierThrowsOnEmptyString(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('invalid identifier');

        $this->callValidateIdentifier('');
    }

    public function testValidateIdentifierThrowsOnStartsWithDigit(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('invalid identifier');

        $this->callValidateIdentifier('123index');
    }

    public function testValidateIdentifierThrowsOnSpecialCharacters(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('invalid identifier');

        $this->callValidateIdentifier('index-name');
    }

    public function testValidateIdentifierThrowsOnSpacesInName(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('invalid identifier');

        $this->callValidateIdentifier('index name');
    }

    public function testValidateIdentifierMultipleValidIndexes(): void
    {
        $this->callValidateIdentifier('idx_a, idx_b, idx_c');
        $this->addToAssertionCount(1);
    }

    private function callValidateIdentifier(string $identifier): void
    {
        $reflectionMethod = new ReflectionMethod(MySqlWalker::class, 'validateIdentifier');

        $reflectionClass = new ReflectionClass(MySqlWalker::class);
        $mySqlWalker = $reflectionClass->newInstanceWithoutConstructor();

        $reflectionMethod->invoke($mySqlWalker, $identifier);
    }
}
