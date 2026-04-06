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

    public function testValidateIndexNameWithValidSingleIndex(): void
    {
        $this->callValidateIndexName('PRIMARY');
        $this->addToAssertionCount(1);
    }

    public function testValidateIndexNameWithValidMultipleIndexes(): void
    {
        $this->callValidateIndexName('PRIMARY, other_index');
        $this->addToAssertionCount(1);
    }

    public function testValidateIndexNameWithUnderscore(): void
    {
        $this->callValidateIndexName('_my_index');
        $this->addToAssertionCount(1);
    }

    public function testValidateIndexNameWithAlphanumeric(): void
    {
        $this->callValidateIndexName('idx_user_123');
        $this->addToAssertionCount(1);
    }

    public function testValidateIndexNameThrowsOnInvalidCharacters(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('invalid identifier');

        $this->callValidateIndexName('DROP TABLE users; --');
    }

    public function testValidateIndexNameThrowsOnEmptyString(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('invalid identifier');

        $this->callValidateIndexName('');
    }

    public function testValidateIndexNameThrowsOnStartsWithDigit(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('invalid identifier');

        $this->callValidateIndexName('123index');
    }

    public function testValidateIndexNameThrowsOnSpecialCharacters(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('invalid identifier');

        $this->callValidateIndexName('index-name');
    }

    public function testValidateIndexNameThrowsOnSpacesInName(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('invalid identifier');

        $this->callValidateIndexName('index name');
    }

    public function testValidateIndexNameMultipleValidIndexes(): void
    {
        $this->callValidateIndexName('idx_a, idx_b, idx_c');
        $this->addToAssertionCount(1);
    }

    private function callValidateIndexName(string $indexName): void
    {
        $reflectionMethod = new ReflectionMethod(MySqlWalker::class, 'validateIdentifier');

        $reflectionClass = new ReflectionClass(MySqlWalker::class);
        $mySqlWalker = $reflectionClass->newInstanceWithoutConstructor();

        $reflectionMethod->invoke($mySqlWalker, $indexName);
    }
}
