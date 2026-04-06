<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Test\Function\Trait;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\Query\SqlWalker;
use Mockery;
use Mockery\MockInterface;

trait SqlWalkerTestTrait
{
    private function createMysqlSqlWalker(): SqlWalker|MockInterface
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDatabasePlatform')
            ->andReturn(new MySQLPlatform());

        $sqlWalker = Mockery::mock(SqlWalker::class);
        $sqlWalker->shouldReceive('getConnection')
            ->andReturn($connection);

        return $sqlWalker;
    }

    private function createNonMysqlSqlWalker(): SqlWalker|MockInterface
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
