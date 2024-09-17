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
use PrecisionSoft\Doctrine\Utility\Join\JoinCollection;
use PrecisionSoft\Doctrine\Utility\Repository\AbstractRepository;
use PrecisionSoft\Doctrine\Utility\Repository\DoctrineRepository;
use PrecisionSoft\Symfony\Phpunit\MockDto;
use PrecisionSoft\Symfony\Phpunit\TestCase\AbstractTestCase;
use Mockery;
use Mockery\MockInterface;
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

    public function test(): void
    {
        $filters = [
            'one' => 'one',
            'two' => 'two',
            'three' => 'three',
        ];

        $method = new ReflectionMethod(AbstractRepository::class, 'createQueryBuilderFromFilters');
        $method->setAccessible(true);

        $mock = $this->get(AbstractRepository::class);
        $mock->shouldAllowMockingProtectedMethods();

        $this->mock($mock);

        $qb = $method->invoke($mock, $filters, true);

        static::assertInstanceOf(QueryBuilder::class, $qb);
    }

    /** @param AbstractRepository $mock */
    private function mock(MockInterface $mock): void
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

        $doctrineRepository = Mockery::mock(DoctrineRepository::class);
        $doctrineRepository->shouldAllowMockingProtectedMethods();
        $doctrineRepository->shouldReceive('createQueryBuilder')
            ->once()
            ->andReturn($queryBuilderMock);
        $doctrineRepository->shouldReceive('getClassMetadata')
            ->byDefault()
            ->andReturn($classMetadataMock);

        $managerRegistry = Mockery::mock(ManagerRegistry::class);
        $managerRegistry->shouldReceive('getRepository')
            ->times(2)
            ->andReturn($doctrineRepository);

        $mock->setManagerRegistry($managerRegistry);

        $mock->shouldReceive('getEntityClass')
            ->times(2)
            ->andReturn('test');

        $mock->shouldReceive('attachCustomFilters')
            ->once()
            ->andReturn(
                (new JoinCollection())->addJoin(
                    new Join(Join::INNER_JOIN, 'test', 't'),
                ),
            );
    }
}
