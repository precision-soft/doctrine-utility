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
use PrecisionSoft\Doctrine\Utility\Function\JsonExtract;
use ReflectionClass;

/**
 * @internal
 */
final class JsonExtractTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testFunctionNameConstant(): void
    {
        static::assertSame('JSON_EXTRACT', JsonExtract::FUNCTION_NAME);
    }

    public function testGetSqlOnMysqlPlatformWithSinglePath(): void
    {
        $sqlWalker = $this->createMysqlSqlWalker();
        $sqlWalker->shouldReceive('walkStringPrimary')
            ->twice()
            ->andReturn('t0_.data', "'$.name'");

        $jsonExtract = $this->createInstance();
        $jsonExtract->jsonDocExpr = Mockery::mock(Node::class);
        $jsonExtract->jsonPaths = [Mockery::mock(Node::class)];

        $sqlDeclaration = $jsonExtract->getSql($sqlWalker);

        static::assertSame("JSON_EXTRACT(t0_.data, '$.name')", $sqlDeclaration);
    }

    public function testGetSqlOnMysqlPlatformWithMultiplePaths(): void
    {
        $sqlWalker = $this->createMysqlSqlWalker();
        $sqlWalker->shouldReceive('walkStringPrimary')
            ->times(3)
            ->andReturn('t0_.data', "'$.name'", "'$.age'");

        $jsonExtract = $this->createInstance();
        $jsonExtract->jsonDocExpr = Mockery::mock(Node::class);
        $jsonExtract->jsonPaths = [Mockery::mock(Node::class), Mockery::mock(Node::class)];

        $sqlDeclaration = $jsonExtract->getSql($sqlWalker);

        static::assertSame("JSON_EXTRACT(t0_.data, '$.name', '$.age')", $sqlDeclaration);
    }

    public function testGetSqlThrowsOnNonMysqlPlatform(): void
    {
        $sqlWalker = $this->createNonMysqlSqlWalker();
        $sqlWalker->shouldReceive('walkStringPrimary')
            ->andReturn('t0_.data', "'$.name'");

        $jsonExtract = $this->createInstance();
        $jsonExtract->jsonDocExpr = Mockery::mock(Node::class);
        $jsonExtract->jsonPaths = [Mockery::mock(Node::class)];

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('method `JSON_EXTRACT` is not supported');

        $jsonExtract->getSql($sqlWalker);
    }

    private function createInstance(): JsonExtract
    {
        $reflectionClass = new ReflectionClass(JsonExtract::class);

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
