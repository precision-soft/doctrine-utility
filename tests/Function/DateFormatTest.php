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
use PrecisionSoft\Doctrine\Utility\Function\DateFormat;
use PrecisionSoft\Doctrine\Utility\Test\Function\Trait\SqlWalkerTestTrait;
use ReflectionClass;

/**
 * @internal
 */
final class DateFormatTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use SqlWalkerTestTrait;

    public function testFunctionNameConstant(): void
    {
        static::assertSame('DATE_FORMAT', DateFormat::FUNCTION_NAME);
    }

    public function testGetSqlReturnsFormattedString(): void
    {
        $sqlWalker = $this->createMysqlSqlWalker();

        $firstNode = Mockery::mock(Node::class);
        $firstNode->shouldReceive('dispatch')
            ->once()
            ->andReturn('t0_.created_at');
        $secondNode = Mockery::mock(Node::class);
        $secondNode->shouldReceive('dispatch')
            ->once()
            ->andReturn("'%Y-%m'");

        $dateFormat = $this->createInstance();
        $dateFormat->firstDateExpression = $firstNode;
        $dateFormat->secondDateExpression = $secondNode;

        $sqlDeclaration = $dateFormat->getSql($sqlWalker);

        static::assertSame("DATE_FORMAT(t0_.created_at, '%Y-%m')", $sqlDeclaration);
    }

    public function testGetSqlReturnsFormattedStringWithStringExpressions(): void
    {
        $sqlWalker = $this->createMysqlSqlWalker();

        $dateFormat = $this->createInstance();
        $dateFormat->firstDateExpression = 't0_.created_at';
        $dateFormat->secondDateExpression = "'%Y-%m'";

        $sqlDeclaration = $dateFormat->getSql($sqlWalker);

        static::assertSame("DATE_FORMAT(t0_.created_at, '%Y-%m')", $sqlDeclaration);
    }

    public function testGetSqlThrowsOnNonMysqlPlatform(): void
    {
        $sqlWalker = $this->createNonMysqlSqlWalker();

        $dateFormat = $this->createInstance();
        $dateFormat->firstDateExpression = Mockery::mock(Node::class);
        $dateFormat->secondDateExpression = Mockery::mock(Node::class);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('function `DATE_FORMAT` is not supported');

        $dateFormat->getSql($sqlWalker);
    }

    private function createInstance(): DateFormat
    {
        $reflectionClass = new ReflectionClass(DateFormat::class);

        return $reflectionClass->newInstanceWithoutConstructor();
    }
}
