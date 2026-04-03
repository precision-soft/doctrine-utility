<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Test\Function;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\SqlWalker;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use PrecisionSoft\Doctrine\Utility\Exception\Exception;
use PrecisionSoft\Doctrine\Utility\Function\JsonUnquote;
use ReflectionClass;

/**
 * @internal
 */
final class JsonUnquoteTest extends TestCase
{
    use MockeryPHPUnitIntegration;

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

    private function createMysqlSqlWalker(): SqlWalker|Mockery\MockInterface
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDatabasePlatform')
            ->andReturn(new MySQLPlatform());

        $sqlWalker = Mockery::mock(SqlWalker::class);
        $sqlWalker->shouldReceive('getConnection')
            ->andReturn($connection);

        return $sqlWalker;
    }

    private function createNonMysqlSqlWalker(): SqlWalker|Mockery\MockInterface
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDatabasePlatform')
            ->andReturn(new PostgreSQLPlatform());

        $sqlWalker = Mockery::mock(SqlWalker::class);
        $sqlWalker->shouldReceive('getConnection')
            ->andReturn($connection);

        return $sqlWalker;
    }
}
