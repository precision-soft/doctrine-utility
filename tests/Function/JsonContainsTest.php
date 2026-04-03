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
use PrecisionSoft\Doctrine\Utility\Function\JsonContains;
use ReflectionClass;

/**
 * @internal
 */
final class JsonContainsTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testFunctionNameConstant(): void
    {
        static::assertSame('JSON_CONTAINS', JsonContains::FUNCTION_NAME);
    }

    public function testGetSqlOnMysqlPlatformWithoutPath(): void
    {
        $sqlWalker = $this->createMysqlSqlWalker();
        $sqlWalker->shouldReceive('walkStringPrimary')
            ->twice()
            ->andReturn('t0_.data', "'value'");

        $jsonContains = $this->createInstance();
        $jsonContains->jsonDocExpr = Mockery::mock(Node::class);
        $jsonContains->jsonValExpr = Mockery::mock(Node::class);

        $sqlDeclaration = $jsonContains->getSql($sqlWalker);

        static::assertSame("JSON_CONTAINS(t0_.data, 'value')", $sqlDeclaration);
    }

    public function testGetSqlOnMysqlPlatformWithPath(): void
    {
        $sqlWalker = $this->createMysqlSqlWalker();
        $sqlWalker->shouldReceive('walkStringPrimary')
            ->times(3)
            ->andReturn('t0_.data', "'value'", "'$.key'");

        $jsonContains = $this->createInstance();
        $jsonContains->jsonDocExpr = Mockery::mock(Node::class);
        $jsonContains->jsonValExpr = Mockery::mock(Node::class);
        $jsonContains->jsonPathExpr = Mockery::mock(Node::class);

        $sqlDeclaration = $jsonContains->getSql($sqlWalker);

        static::assertSame("JSON_CONTAINS(t0_.data, 'value', '$.key')", $sqlDeclaration);
    }

    public function testGetSqlThrowsOnNonMysqlPlatform(): void
    {
        $sqlWalker = $this->createNonMysqlSqlWalker();
        $sqlWalker->shouldReceive('walkStringPrimary')
            ->andReturn('t0_.data', "'value'");

        $jsonContains = $this->createInstance();
        $jsonContains->jsonDocExpr = Mockery::mock(Node::class);
        $jsonContains->jsonValExpr = Mockery::mock(Node::class);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('method `JSON_CONTAINS` is not supported');

        $jsonContains->getSql($sqlWalker);
    }

    private function createInstance(): JsonContains
    {
        $reflectionClass = new ReflectionClass(JsonContains::class);

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
