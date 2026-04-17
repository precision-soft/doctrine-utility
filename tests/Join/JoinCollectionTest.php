<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Test\Join;

use Doctrine\ORM\Query\Expr\Join;
use PHPUnit\Framework\TestCase;
use PrecisionSoft\Doctrine\Utility\Exception\Exception;
use PrecisionSoft\Doctrine\Utility\Join\JoinCollection;

/**
 * @internal
 */
final class JoinCollectionTest extends TestCase
{
    public function testGetJoinsReturnsEmptyArrayByDefault(): void
    {
        $joinCollection = new JoinCollection();

        static::assertSame([], $joinCollection->getJoins());
    }

    public function testAddJoinAndGetJoins(): void
    {
        $joinCollection = new JoinCollection();
        $join = new Join(Join::INNER_JOIN, 'entity', 'e');

        $returnValue = $joinCollection->addJoin($join);

        static::assertSame($joinCollection, $returnValue);
        static::assertCount(1, $joinCollection->getJoins());
        static::assertSame($join, $joinCollection->getJoins()['e']);
    }

    public function testAddMultipleJoins(): void
    {
        $joinCollection = new JoinCollection();
        $innerJoin = new Join(Join::INNER_JOIN, 'entity1', 'e1');
        $leftJoin = new Join(Join::LEFT_JOIN, 'entity2', 'e2');

        $joinCollection->addJoin($innerJoin);
        $joinCollection->addJoin($leftJoin);

        $joins = $joinCollection->getJoins();
        static::assertCount(2, $joins);
        static::assertSame($innerJoin, $joins['e1']);
        static::assertSame($leftJoin, $joins['e2']);
    }

    public function testAddJoinThrowsOnEmptyAlias(): void
    {
        $joinCollection = new JoinCollection();
        $join = new Join(Join::INNER_JOIN, 'entity', '');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('alias cannot be empty');

        $joinCollection->addJoin($join);
    }

    public function testAddJoinThrowsOnWhitespaceOnlyAlias(): void
    {
        $joinCollection = new JoinCollection();
        $join = new Join(Join::INNER_JOIN, 'entity', '   ');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('alias cannot be empty');

        $joinCollection->addJoin($join);
    }

    public function testAddJoinThrowsOnDuplicateAlias(): void
    {
        $joinCollection = new JoinCollection();
        $firstJoin = new Join(Join::INNER_JOIN, 'entity1', 'e');
        $secondJoin = new Join(Join::LEFT_JOIN, 'entity2', 'e');

        $joinCollection->addJoin($firstJoin);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('duplicate alias `e` in join collection');

        $joinCollection->addJoin($secondJoin);
    }

    public function testGetAliasesReturnsEmptyArrayByDefault(): void
    {
        $joinCollection = new JoinCollection();

        static::assertSame([], $joinCollection->getAliases());
    }

    public function testGetAliasesReturnsCorrectAliases(): void
    {
        $joinCollection = new JoinCollection();
        $joinCollection->addJoin(new Join(Join::INNER_JOIN, 'entity1', 'a'));
        $joinCollection->addJoin(new Join(Join::LEFT_JOIN, 'entity2', 'b'));
        $joinCollection->addJoin(new Join(Join::INNER_JOIN, 'entity3', 'c'));

        static::assertSame(['a', 'b', 'c'], $joinCollection->getAliases());
    }

    public function testAddJoinReturnsSelfForChaining(): void
    {
        $joinCollection = new JoinCollection();
        $join = new Join(Join::INNER_JOIN, 'entity', 'e');

        $returnValue = $joinCollection->addJoin($join);

        static::assertInstanceOf(JoinCollection::class, $returnValue);
        static::assertSame($joinCollection, $returnValue);
    }
}
