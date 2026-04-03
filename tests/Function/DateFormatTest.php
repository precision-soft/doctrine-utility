<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Test\Function;

use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\SqlWalker;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use PrecisionSoft\Doctrine\Utility\Function\DateFormat;
use ReflectionClass;

/**
 * @internal
 */
final class DateFormatTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testFunctionNameConstant(): void
    {
        static::assertSame('DATE_FORMAT', DateFormat::FUNCTION_NAME);
    }

    public function testGetSqlReturnsFormattedString(): void
    {
        $firstNode = Mockery::mock(Node::class);
        $firstNode->shouldReceive('dispatch')
            ->once()
            ->andReturn('t0_.created_at');
        $secondNode = Mockery::mock(Node::class);
        $secondNode->shouldReceive('dispatch')
            ->once()
            ->andReturn("'%Y-%m'");

        $sqlWalker = Mockery::mock(SqlWalker::class);

        $dateFormat = $this->createInstance();
        $dateFormat->firstDateExpression = $firstNode;
        $dateFormat->secondDateExpression = $secondNode;

        $sqlDeclaration = $dateFormat->getSql($sqlWalker);

        static::assertSame("DATE_FORMAT(t0_.created_at, '%Y-%m')", $sqlDeclaration);
    }

    private function createInstance(): DateFormat
    {
        $reflectionClass = new ReflectionClass(DateFormat::class);

        return $reflectionClass->newInstanceWithoutConstructor();
    }
}
