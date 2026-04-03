<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Test\Repository;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Mockery;
use Mockery\MockInterface;
use PrecisionSoft\Doctrine\Utility\Join\JoinCollection;
use PrecisionSoft\Doctrine\Utility\Repository\AbstractRepository;
use PrecisionSoft\Doctrine\Utility\Repository\DoctrineRepository;
use PrecisionSoft\Symfony\Phpunit\MockDto;
use PrecisionSoft\Symfony\Phpunit\TestCase\AbstractTestCase;
use ReflectionMethod;

/**
 * @internal
 */
final class AbstractRepositoryTest extends AbstractTestCase
{
    public static function getMockDto(): MockDto
    {
        return new MockDto(
            AbstractRepository::class,
            [],
            true,
        );
    }

    public function testCreateQueryBuilderFromFiltersWithSelectJoins(): void
    {
        $filters = [
            'one' => 'one',
            'two' => 'two',
            'three' => 'three',
        ];

        $reflectionMethod = new ReflectionMethod(AbstractRepository::class, 'createQueryBuilderFromFilters');
        $reflectionMethod->setAccessible(true);

        $abstractRepositoryMock = $this->get(AbstractRepository::class);
        $abstractRepositoryMock->shouldAllowMockingProtectedMethods();

        $this->mockAbstractRepository($abstractRepositoryMock);

        $queryBuilder = $reflectionMethod->invoke($abstractRepositoryMock, $filters, true);

        static::assertInstanceOf(QueryBuilder::class, $queryBuilder);
    }

    /**
     * @param AbstractRepository $abstractRepositoryMock
     */
    private function mockAbstractRepository(MockInterface $abstractRepositoryMock): void
    {
        $classMetadataMock = Mockery::mock(ClassMetadata::class);
        $classMetadataMock->shouldReceive('hasField')
            ->once()
            ->andReturn(true);
        $classMetadataMock->shouldReceive('hasField', 'hasAssociation')
            ->times(2)
            ->andReturn(false);

        $queryBuilderMock = Mockery::mock(QueryBuilder::class);
        $queryBuilderMock->shouldReceive('andWhere', 'setParameter', 'innerJoin', 'addSelect')
            ->byDefault()
            ->andReturnSelf();

        $doctrineRepositoryMock = Mockery::mock(DoctrineRepository::class);
        $doctrineRepositoryMock->shouldAllowMockingProtectedMethods();
        $doctrineRepositoryMock->shouldReceive('createQueryBuilder')
            ->once()
            ->andReturn($queryBuilderMock);
        $doctrineRepositoryMock->shouldReceive('getClassMetadata')
            ->byDefault()
            ->andReturn($classMetadataMock);

        $managerRegistryMock = Mockery::mock(ManagerRegistry::class);
        $managerRegistryMock->shouldReceive('getRepository')
            ->times(2)
            ->andReturn($doctrineRepositoryMock);

        $abstractRepositoryMock->setManagerRegistry($managerRegistryMock);

        $abstractRepositoryMock->shouldReceive('getEntityClass')
            ->times(2)
            ->andReturn('test');

        $abstractRepositoryMock->shouldReceive('attachCustomFilters')
            ->once()
            ->andReturn(
                (new JoinCollection())->addJoin(
                    new Join(Join::INNER_JOIN, 'test', 't'),
                ),
            );
    }
}
