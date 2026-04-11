<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Test\Function;

use Doctrine\ORM\Query\AST\Node;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use PrecisionSoft\Doctrine\Utility\Exception\Exception;
use PrecisionSoft\Doctrine\Utility\Function\JsonSearch;
use PrecisionSoft\Doctrine\Utility\Test\Function\Trait\SqlWalkerTestTrait;
use ReflectionClass;

/**
 * @internal
 */
final class JsonSearchTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use SqlWalkerTestTrait;

    public function testFunctionNameConstant(): void
    {
        static::assertSame('JSON_SEARCH', JsonSearch::FUNCTION_NAME);
    }

    public function testGetSqlOnMysqlPlatformWithoutEscape(): void
    {
        $sqlWalker = $this->createMysqlSqlWalker();
        $sqlWalker->shouldReceive('walkStringPrimary')
            ->times(3)
            ->andReturn('t0_.data', "'one'", "'search_value'");

        $jsonSearch = $this->createInstance();
        $jsonSearch->jsonDocExpr = Mockery::mock(Node::class);
        $jsonSearch->mode = Mockery::mock(Node::class);
        $jsonSearch->jsonSearchExpr = Mockery::mock(Node::class);

        $sqlDeclaration = $jsonSearch->getSql($sqlWalker);

        static::assertSame("JSON_SEARCH(t0_.data, 'one', 'search_value')", $sqlDeclaration);
    }

    public function testGetSqlOnMysqlPlatformWithEscapeAndPaths(): void
    {
        $sqlWalker = $this->createMysqlSqlWalker();
        $sqlWalker->shouldReceive('walkStringPrimary')
            ->times(5)
            ->andReturn('t0_.data', "'all'", "'search'", "'#'", "'$.name'");

        $jsonSearch = $this->createInstance();
        $jsonSearch->jsonDocExpr = Mockery::mock(Node::class);
        $jsonSearch->mode = Mockery::mock(Node::class);
        $jsonSearch->jsonSearchExpr = Mockery::mock(Node::class);
        $jsonSearch->jsonEscapeExpr = Mockery::mock(Node::class);
        $jsonSearch->jsonPaths = [Mockery::mock(Node::class)];

        $sqlDeclaration = $jsonSearch->getSql($sqlWalker);

        static::assertSame("JSON_SEARCH(t0_.data, 'all', 'search', '#', '$.name')", $sqlDeclaration);
    }

    public function testGetSqlOnMysqlPlatformWithEscapeWithoutPaths(): void
    {
        $sqlWalker = $this->createMysqlSqlWalker();
        $sqlWalker->shouldReceive('walkStringPrimary')
            ->times(4)
            ->andReturn('t0_.data', "'one'", "'search'", "'#'");

        $jsonSearch = $this->createInstance();
        $jsonSearch->jsonDocExpr = Mockery::mock(Node::class);
        $jsonSearch->mode = Mockery::mock(Node::class);
        $jsonSearch->jsonSearchExpr = Mockery::mock(Node::class);
        $jsonSearch->jsonEscapeExpr = Mockery::mock(Node::class);

        $sqlDeclaration = $jsonSearch->getSql($sqlWalker);

        static::assertSame("JSON_SEARCH(t0_.data, 'one', 'search', '#')", $sqlDeclaration);
    }

    public function testGetSqlThrowsOnNonMysqlPlatform(): void
    {
        $sqlWalker = $this->createNonMysqlSqlWalker();
        $sqlWalker->shouldReceive('walkStringPrimary')
            ->andReturn('t0_.data', "'one'", "'search'");

        $jsonSearch = $this->createInstance();
        $jsonSearch->jsonDocExpr = Mockery::mock(Node::class);
        $jsonSearch->mode = Mockery::mock(Node::class);
        $jsonSearch->jsonSearchExpr = Mockery::mock(Node::class);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('function `JSON_SEARCH` is not supported');

        $jsonSearch->getSql($sqlWalker);
    }

    private function createInstance(): JsonSearch
    {
        $reflectionClass = new ReflectionClass(JsonSearch::class);

        return $reflectionClass->newInstanceWithoutConstructor();
    }
}
