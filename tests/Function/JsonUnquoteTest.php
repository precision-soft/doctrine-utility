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
use PrecisionSoft\Doctrine\Utility\Function\JsonUnquote;
use PrecisionSoft\Doctrine\Utility\Test\Function\Trait\SqlWalkerTestTrait;
use ReflectionClass;

/**
 * @internal
 */
final class JsonUnquoteTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use SqlWalkerTestTrait;

    public function testFunctionNameConstant(): void
    {
        static::assertSame('JSON_UNQUOTE', JsonUnquote::FUNCTION_NAME);
    }

    public function testGetSqlOnMysqlPlatform(): void
    {
        $sqlWalker = $this->createMysqlSqlWalker();
        $sqlWalker->shouldReceive('walkStringPrimary')
            ->once()
            ->andReturn('t0_.data');

        $jsonUnquote = $this->createInstance();
        $jsonUnquote->jsonValExpr = Mockery::mock(Node::class);

        $sqlDeclaration = $jsonUnquote->getSql($sqlWalker);

        static::assertSame('JSON_UNQUOTE(t0_.data)', $sqlDeclaration);
    }

    public function testGetSqlThrowsOnNonMysqlPlatform(): void
    {
        $sqlWalker = $this->createNonMysqlSqlWalker();
        $sqlWalker->shouldReceive('walkStringPrimary')
            ->once()
            ->andReturn('t0_.data');

        $jsonUnquote = $this->createInstance();
        $jsonUnquote->jsonValExpr = Mockery::mock(Node::class);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('method `JSON_UNQUOTE` is not supported');

        $jsonUnquote->getSql($sqlWalker);
    }

    private function createInstance(): JsonUnquote
    {
        $reflectionClass = new ReflectionClass(JsonUnquote::class);

        return $reflectionClass->newInstanceWithoutConstructor();
    }
}
