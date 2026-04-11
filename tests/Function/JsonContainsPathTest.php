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
use PrecisionSoft\Doctrine\Utility\Function\JsonContainsPath;
use PrecisionSoft\Doctrine\Utility\Test\Function\Trait\SqlWalkerTestTrait;
use ReflectionClass;

/**
 * @internal
 */
final class JsonContainsPathTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use SqlWalkerTestTrait;

    public function testFunctionNameConstant(): void
    {
        static::assertSame('JSON_CONTAINS_PATH', JsonContainsPath::FUNCTION_NAME);
    }

    public function testGetSqlOnMysqlPlatformWithSinglePath(): void
    {
        $sqlWalker = $this->createMysqlSqlWalker();
        $sqlWalker->shouldReceive('walkStringPrimary')
            ->times(3)
            ->andReturn('t0_.data', "'one'", "'$.key'");

        $jsonContainsPath = $this->createInstance();
        $jsonContainsPath->jsonDocExpr = Mockery::mock(Node::class);
        $jsonContainsPath->mode = Mockery::mock(Node::class);
        $jsonContainsPath->jsonPaths = [Mockery::mock(Node::class)];

        $sqlDeclaration = $jsonContainsPath->getSql($sqlWalker);

        static::assertSame("JSON_CONTAINS_PATH(t0_.data, 'one', '$.key')", $sqlDeclaration);
    }

    public function testGetSqlOnMysqlPlatformWithMultiplePaths(): void
    {
        $sqlWalker = $this->createMysqlSqlWalker();
        $sqlWalker->shouldReceive('walkStringPrimary')
            ->times(4)
            ->andReturn('t0_.data', "'all'", "'$.name'", "'$.age'");

        $jsonContainsPath = $this->createInstance();
        $jsonContainsPath->jsonDocExpr = Mockery::mock(Node::class);
        $jsonContainsPath->mode = Mockery::mock(Node::class);
        $jsonContainsPath->jsonPaths = [Mockery::mock(Node::class), Mockery::mock(Node::class)];

        $sqlDeclaration = $jsonContainsPath->getSql($sqlWalker);

        static::assertSame("JSON_CONTAINS_PATH(t0_.data, 'all', '$.name', '$.age')", $sqlDeclaration);
    }

    public function testGetSqlThrowsOnNonMysqlPlatform(): void
    {
        $sqlWalker = $this->createNonMysqlSqlWalker();
        $sqlWalker->shouldReceive('walkStringPrimary')
            ->andReturn('t0_.data', "'one'", "'$.key'");

        $jsonContainsPath = $this->createInstance();
        $jsonContainsPath->jsonDocExpr = Mockery::mock(Node::class);
        $jsonContainsPath->mode = Mockery::mock(Node::class);
        $jsonContainsPath->jsonPaths = [Mockery::mock(Node::class)];

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('function `JSON_CONTAINS_PATH` is not supported');

        $jsonContainsPath->getSql($sqlWalker);
    }

    private function createInstance(): JsonContainsPath
    {
        $reflectionClass = new ReflectionClass(JsonContainsPath::class);

        return $reflectionClass->newInstanceWithoutConstructor();
    }
}
