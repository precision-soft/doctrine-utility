<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Test\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Statement;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use Mockery;
use Mockery\MockInterface;
use PrecisionSoft\Doctrine\Utility\Exception\Exception;
use PrecisionSoft\Doctrine\Utility\Join\JoinCollection;
use PrecisionSoft\Doctrine\Utility\Repository\AbstractRepository;
use PrecisionSoft\Doctrine\Utility\Repository\DoctrineRepository;
use PrecisionSoft\Doctrine\Utility\Repository\EmptyArrayFilterBehavior;
use PrecisionSoft\Doctrine\Utility\Test\Repository\Fixture\BinaryUuidType;
use PrecisionSoft\Symfony\Phpunit\MockDto;
use PrecisionSoft\Symfony\Phpunit\TestCase\AbstractTestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionProperty;
use stdClass;

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

        $abstractRepositoryMock = $this->get(AbstractRepository::class);
        $abstractRepositoryMock->shouldAllowMockingProtectedMethods();

        $this->mockAbstractRepository($abstractRepositoryMock);

        $queryBuilder = $reflectionMethod->invoke($abstractRepositoryMock, $filters, true);

        static::assertInstanceOf(QueryBuilder::class, $queryBuilder);
    }

    public function testAttachJoinsThrowsExceptionOnInvalidJoinType(): void
    {
        $reflectionMethod = new ReflectionMethod(AbstractRepository::class, 'attachJoins');

        $abstractRepositoryMock = $this->get(AbstractRepository::class);

        $queryBuilderMock = Mockery::mock(QueryBuilder::class);

        $invalidJoin = new Join('INVALID_JOIN', 'entity', 'e');
        $joinCollection = new JoinCollection();
        $joinCollection->addJoin($invalidJoin);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('invalid join type `INVALID_JOIN`');

        $reflectionMethod->invoke($abstractRepositoryMock, $queryBuilderMock, $joinCollection);
    }

    public function testAttachJoinsThrowsExceptionOnNullAlias(): void
    {
        $reflectionMethod = new ReflectionMethod(AbstractRepository::class, 'attachJoins');

        $abstractRepositoryMock = $this->get(AbstractRepository::class);

        $queryBuilderMock = Mockery::mock(QueryBuilder::class);

        $joinWithNullAlias = new Join(Join::INNER_JOIN, 'entity');
        $joinCollection = new JoinCollection();

        /** @info bypass JoinCollection::addJoin() which rejects null aliases; we want to verify attachJoins() also guards at runtime */
        $reflectionProperty = new ReflectionProperty(JoinCollection::class, 'joins');
        $reflectionProperty->setValue($joinCollection, ['x' => $joinWithNullAlias]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('join alias must not be null');

        $reflectionMethod->invoke($abstractRepositoryMock, $queryBuilderMock, $joinCollection);
    }

    public function testGetConnectionThrowsWhenRegistryReturnsWrongConnectionType(): void
    {
        $reflectionMethod = new ReflectionMethod(AbstractRepository::class, 'getConnection');

        $abstractRepositoryMock = $this->get(AbstractRepository::class);

        $notADbalConnection = new stdClass();

        $managerRegistryMock = Mockery::mock(ManagerRegistry::class);
        $managerRegistryMock->shouldReceive('getConnection')
            ->with(null)
            ->once()
            ->andReturn($notADbalConnection);

        $abstractRepositoryMock->setManagerRegistry($managerRegistryMock);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('connection is not an instance of Connection');

        $reflectionMethod->invoke($abstractRepositoryMock);
    }

    public function testGetDoctrineRepositoryThrowsExceptionOnWrongClass(): void
    {
        $reflectionMethod = new ReflectionMethod(AbstractRepository::class, 'getDoctrineRepository');

        $abstractRepositoryMock = $this->get(AbstractRepository::class);
        $abstractRepositoryMock->shouldAllowMockingProtectedMethods();

        $objectRepositoryMock = Mockery::mock(ObjectRepository::class);

        $managerRegistryMock = Mockery::mock(ManagerRegistry::class);
        $managerRegistryMock->shouldReceive('getRepository')
            ->once()
            ->andReturn($objectRepositoryMock);

        $abstractRepositoryMock->setManagerRegistry($managerRegistryMock);

        $abstractRepositoryMock->shouldReceive('getEntityClass')
            ->once()
            ->andReturn('test');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('you must use');

        $reflectionMethod->invoke($abstractRepositoryMock);
    }

    public function testAttachGenericFiltersEmptyArrayMatchesNoneByDefault(): void
    {
        $reflectionMethod = new ReflectionMethod(AbstractRepository::class, 'attachGenericFilters');

        $abstractRepositoryMock = $this->get(AbstractRepository::class);
        $abstractRepositoryMock->shouldAllowMockingProtectedMethods();

        $queryBuilderMock = Mockery::mock(QueryBuilder::class);
        $queryBuilderMock->shouldReceive('andWhere')
            ->with("'name' = 'name-emptyFilter'")
            ->once()
            ->andReturnSelf();

        $reflectionMethod->invoke($abstractRepositoryMock, $queryBuilderMock, ['name' => []]);
    }

    public function testAttachGenericFiltersEmptyArrayThrowsWhenFlagOverridden(): void
    {
        $reflectionMethod = new ReflectionMethod(AbstractRepository::class, 'attachGenericFilters');

        $abstractRepositoryMock = $this->get(AbstractRepository::class);
        $abstractRepositoryMock->shouldAllowMockingProtectedMethods();
        $abstractRepositoryMock->shouldReceive('getFlags')
            ->andReturn([
                EmptyArrayFilterBehavior::class => EmptyArrayFilterBehavior::ThrowException,
            ]);

        $queryBuilderMock = Mockery::mock(QueryBuilder::class);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('invalid filter `name`');

        $reflectionMethod->invoke($abstractRepositoryMock, $queryBuilderMock, ['name' => []]);
    }

    public function testAttachGenericFiltersEmptyArrayLogsWarningWhenLoggerProvided(): void
    {
        $reflectionMethod = new ReflectionMethod(AbstractRepository::class, 'attachGenericFilters');

        $abstractRepositoryMock = $this->get(AbstractRepository::class);
        $abstractRepositoryMock->shouldAllowMockingProtectedMethods();

        $loggerMock = Mockery::mock(LoggerInterface::class);
        $loggerMock->shouldReceive('warning')
            ->with(
                'empty array filter forced to match no rows',
                Mockery::on(static function (array $context): bool {
                    return 'name' === ($context['filter'] ?? null)
                        && true === \is_string($context['repository'] ?? null)
                        && true === \is_string($context['hint'] ?? null);
                }),
            )
            ->once();

        $abstractRepositoryMock->shouldReceive('getLogger')
            ->andReturn($loggerMock);

        $queryBuilderMock = Mockery::mock(QueryBuilder::class);
        $queryBuilderMock->shouldReceive('andWhere')
            ->with("'name' = 'name-emptyFilter'")
            ->once()
            ->andReturnSelf();

        $reflectionMethod->invoke($abstractRepositoryMock, $queryBuilderMock, ['name' => []]);
    }

    public function testExecuteRunsQueryWithParameters(): void
    {
        $reflectionMethod = new ReflectionMethod(AbstractRepository::class, 'execute');

        $abstractRepositoryMock = $this->get(AbstractRepository::class);
        $abstractRepositoryMock->shouldAllowMockingProtectedMethods();

        $resultMock = Mockery::mock(Result::class);

        $statementMock = Mockery::mock(Statement::class);
        $statementMock->shouldReceive('bindValue')
            ->with('name', 'test')
            ->once();
        $statementMock->shouldReceive('bindValue')
            ->with('status', 'active')
            ->once();
        $statementMock->shouldReceive('executeQuery')
            ->once()
            ->andReturn($resultMock);

        $connectionMock = Mockery::mock(Connection::class);
        $connectionMock->shouldReceive('prepare')
            ->with('SELECT * FROM test WHERE name = :name AND status = :status')
            ->once()
            ->andReturn($statementMock);

        $managerRegistryMock = Mockery::mock(ManagerRegistry::class);
        $managerRegistryMock->shouldReceive('getConnection')
            ->with(null)
            ->once()
            ->andReturn($connectionMock);

        $abstractRepositoryMock->setManagerRegistry($managerRegistryMock);

        $executeResult = $reflectionMethod->invoke(
            $abstractRepositoryMock,
            'SELECT * FROM test WHERE name = :name AND status = :status',
            ['name' => 'test', 'status' => 'active'],
        );

        static::assertInstanceOf(Result::class, $executeResult);
    }

    public function testAttachGenericFiltersBindsUuidArrayAsBinary(): void
    {
        if (false === Type::hasType(BinaryUuidType::NAME)) {
            Type::addType(BinaryUuidType::NAME, BinaryUuidType::class);
        }

        $uuids = [
            '00000000-0000-0000-0000-000000000001',
            '00000000-0000-0000-0000-000000000002',
        ];
        $expectedBinaryValues = \array_map(
            static fn(string $uuid): string => \hex2bin(\str_replace('-', '', $uuid)),
            $uuids,
        );

        $classMetadataMock = Mockery::mock(ClassMetadata::class);
        $classMetadataMock->shouldReceive('getTypeOfField')
            ->with('id')
            ->andReturn(BinaryUuidType::NAME);
        $classMetadataMock->shouldReceive('hasField')
            ->with('id')
            ->andReturn(true);

        $abstractRepositoryMock = $this->mockRepositoryWithManager($classMetadataMock);

        $queryBuilderMock = Mockery::mock(QueryBuilder::class);
        $queryBuilderMock->shouldReceive('andWhere')
            ->andReturnSelf();
        $queryBuilderMock->shouldReceive('setParameter')
            ->with('id', $expectedBinaryValues, ArrayParameterType::BINARY)
            ->once()
            ->andReturnSelf();

        $reflectionMethod = new ReflectionMethod(AbstractRepository::class, 'attachGenericFilters');
        $reflectionMethod->invoke($abstractRepositoryMock, $queryBuilderMock, ['id' => $uuids]);
    }

    public function testAttachGenericFiltersBindsIntegerArrayAsIntegerType(): void
    {
        $numbers = [1, 2, 3];

        $classMetadataMock = Mockery::mock(ClassMetadata::class);
        $classMetadataMock->shouldReceive('getTypeOfField')
            ->with('position')
            ->andReturn('integer');
        $classMetadataMock->shouldReceive('hasField')
            ->with('position')
            ->andReturn(true);

        $abstractRepositoryMock = $this->mockRepositoryWithManager($classMetadataMock);

        $queryBuilderMock = Mockery::mock(QueryBuilder::class);
        $queryBuilderMock->shouldReceive('andWhere')
            ->andReturnSelf();
        $queryBuilderMock->shouldReceive('setParameter')
            ->with('position', $numbers, ArrayParameterType::INTEGER)
            ->once()
            ->andReturnSelf();

        $reflectionMethod = new ReflectionMethod(AbstractRepository::class, 'attachGenericFilters');
        $reflectionMethod->invoke($abstractRepositoryMock, $queryBuilderMock, ['position' => $numbers]);
    }

    public function testAttachGenericFiltersBindsStringArrayAsStringType(): void
    {
        $codes = ['alpha', 'beta'];

        $classMetadataMock = Mockery::mock(ClassMetadata::class);
        $classMetadataMock->shouldReceive('getTypeOfField')
            ->with('code')
            ->andReturn('string');
        $classMetadataMock->shouldReceive('hasField')
            ->with('code')
            ->andReturn(true);

        $abstractRepositoryMock = $this->mockRepositoryWithManager($classMetadataMock);

        $queryBuilderMock = Mockery::mock(QueryBuilder::class);
        $queryBuilderMock->shouldReceive('andWhere')
            ->andReturnSelf();
        $queryBuilderMock->shouldReceive('setParameter')
            ->with('code', $codes, ArrayParameterType::STRING)
            ->once()
            ->andReturnSelf();

        $reflectionMethod = new ReflectionMethod(AbstractRepository::class, 'attachGenericFilters');
        $reflectionMethod->invoke($abstractRepositoryMock, $queryBuilderMock, ['code' => $codes]);
    }

    public function testAttachGenericFiltersBindsAssociationArrayWithoutType(): void
    {
        $ownerValues = ['owner-1', 'owner-2'];

        $classMetadataMock = Mockery::mock(ClassMetadata::class);
        $classMetadataMock->shouldReceive('getTypeOfField')
            ->with('owner')
            ->andReturn(null);
        $classMetadataMock->shouldReceive('hasField')
            ->with('owner')
            ->andReturn(false);

        $abstractRepositoryMock = $this->mockRepositoryWithManager($classMetadataMock);

        $queryBuilderMock = Mockery::mock(QueryBuilder::class);
        $queryBuilderMock->shouldReceive('andWhere')
            ->andReturnSelf();

        /** @info the owning-side association keeps the untyped binding — setParameter() is called with two arguments only */
        $queryBuilderMock->shouldReceive('setParameter')
            ->with('owner', $ownerValues)
            ->once()
            ->andReturnSelf();

        $reflectionMethod = new ReflectionMethod(AbstractRepository::class, 'attachGenericFilters');
        $reflectionMethod->invoke($abstractRepositoryMock, $queryBuilderMock, ['owner' => $ownerValues]);
    }

    public function testAttachGenericFiltersBindsSingleValuesWithoutType(): void
    {
        $abstractRepositoryMock = $this->get(AbstractRepository::class);
        $abstractRepositoryMock->shouldAllowMockingProtectedMethods();

        $uuid = '00000000-0000-0000-0000-000000000009';

        $queryBuilderMock = Mockery::mock(QueryBuilder::class);
        $queryBuilderMock->shouldReceive('andWhere')
            ->andReturnSelf();

        /** @info single-value `=` filters never resolve the manager: the binding stays untyped for uuid/int/string alike */
        $queryBuilderMock->shouldReceive('setParameter')
            ->with('id', $uuid)
            ->once()
            ->andReturnSelf();
        $queryBuilderMock->shouldReceive('setParameter')
            ->with('position', 5)
            ->once()
            ->andReturnSelf();
        $queryBuilderMock->shouldReceive('setParameter')
            ->with('code', 'active')
            ->once()
            ->andReturnSelf();

        $reflectionMethod = new ReflectionMethod(AbstractRepository::class, 'attachGenericFilters');
        $reflectionMethod->invoke(
            $abstractRepositoryMock,
            $queryBuilderMock,
            ['id' => $uuid, 'position' => 5, 'code' => 'active'],
        );
    }

    public function testAttachGenericFiltersBindsArrayWithoutTypeWhenManagerIsNotOrm(): void
    {
        $ids = ['id-1', 'id-2'];

        $abstractRepositoryMock = $this->get(AbstractRepository::class);
        $abstractRepositoryMock->shouldAllowMockingProtectedMethods();
        $abstractRepositoryMock->shouldReceive('getEntityClass')
            ->andReturn('Entity');

        $objectManagerMock = Mockery::mock(ObjectManager::class);

        $managerRegistryMock = Mockery::mock(ManagerRegistry::class);
        $managerRegistryMock->shouldReceive('getManagerForClass')
            ->with('Entity')
            ->andReturn($objectManagerMock);

        $abstractRepositoryMock->setManagerRegistry($managerRegistryMock);

        $queryBuilderMock = Mockery::mock(QueryBuilder::class);
        $queryBuilderMock->shouldReceive('andWhere')
            ->andReturnSelf();

        /** @info a non-ORM manager cannot resolve field types, so the binding stays untyped — exactly the pre-fix behavior, no exception */
        $queryBuilderMock->shouldReceive('setParameter')
            ->with('id', $ids)
            ->once()
            ->andReturnSelf();

        $reflectionMethod = new ReflectionMethod(AbstractRepository::class, 'attachGenericFilters');
        $reflectionMethod->invoke($abstractRepositoryMock, $queryBuilderMock, ['id' => $ids]);
    }

    /**
     * @param ClassMetadata $classMetadataMock
     *
     * @return AbstractRepository&MockInterface
     */
    private function mockRepositoryWithManager(MockInterface $classMetadataMock): MockInterface
    {
        $abstractRepositoryMock = $this->get(AbstractRepository::class);
        $abstractRepositoryMock->shouldAllowMockingProtectedMethods();
        $abstractRepositoryMock->shouldReceive('getEntityClass')
            ->andReturn('Entity');

        $connectionMock = Mockery::mock(Connection::class);
        $connectionMock->shouldReceive('getDatabasePlatform')
            ->andReturn(Mockery::mock(AbstractPlatform::class));

        $entityManagerMock = Mockery::mock(EntityManagerInterface::class);
        $entityManagerMock->shouldReceive('getClassMetadata')
            ->with('Entity')
            ->andReturn($classMetadataMock);
        $entityManagerMock->shouldReceive('getConnection')
            ->andReturn($connectionMock);

        $managerRegistryMock = Mockery::mock(ManagerRegistry::class);
        $managerRegistryMock->shouldReceive('getManagerForClass')
            ->with('Entity')
            ->andReturn($entityManagerMock);

        $abstractRepositoryMock->setManagerRegistry($managerRegistryMock);

        return $abstractRepositoryMock;
    }

    /**
     * @param AbstractRepository $abstractRepositoryMock
     */
    private function mockAbstractRepository(MockInterface $abstractRepositoryMock): void
    {
        $classMetadataMock = Mockery::mock(ClassMetadata::class);

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
        $doctrineRepositoryMock->shouldReceive('hasField')
            ->once()
            ->with('one')
            ->andReturn(true);
        $doctrineRepositoryMock->shouldReceive('hasField')
            ->once()
            ->with('two')
            ->andReturn(false);
        $doctrineRepositoryMock->shouldReceive('hasField')
            ->once()
            ->with('three')
            ->andReturn(false);

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
