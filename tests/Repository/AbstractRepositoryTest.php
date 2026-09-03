<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Test\Repository;

use DateTime;
use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
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
use Error;
use Mockery;
use Mockery\MockInterface;
use PrecisionSoft\Doctrine\Utility\Exception\Exception;
use PrecisionSoft\Doctrine\Utility\Join\JoinCollection;
use PrecisionSoft\Doctrine\Utility\Repository\AbstractRepository;
use PrecisionSoft\Doctrine\Utility\Repository\Criteria\Criteria;
use PrecisionSoft\Doctrine\Utility\Repository\Criteria\Filter;
use PrecisionSoft\Doctrine\Utility\Repository\Criteria\Keyset;
use PrecisionSoft\Doctrine\Utility\Repository\Criteria\Operator;
use PrecisionSoft\Doctrine\Utility\Repository\Criteria\Sort;
use PrecisionSoft\Doctrine\Utility\Repository\DoctrineRepository;
use PrecisionSoft\Doctrine\Utility\Repository\EmptyArrayFilterBehavior;
use PrecisionSoft\Doctrine\Utility\Test\Repository\Fixture\BinaryUuidType;
use PrecisionSoft\Doctrine\Utility\Test\Repository\Fixture\BrokenUidType;
use PrecisionSoft\Doctrine\Utility\Test\Repository\Fixture\CustomUidType;
use PrecisionSoft\Doctrine\Utility\Test\Repository\Fixture\IntBackedEnum;
use PrecisionSoft\Doctrine\Utility\Test\Repository\Fixture\StrictBinaryUuidType;
use PrecisionSoft\Doctrine\Utility\Test\Repository\Fixture\StringBackedEnum;
use PrecisionSoft\Doctrine\Utility\Test\Utility\Exception\FixtureException;
use PrecisionSoft\Symfony\Phpunit\MockDto;
use PrecisionSoft\Symfony\Phpunit\TestCase\AbstractTestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionProperty;
use stdClass;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

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

        /* built without addJoin(), which rejects a null alias itself, so attachJoins()' own guard can be reached */
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
        $this->expectExceptionMessage('connection is not an instance of `Connection`');

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
        $this->registerType(BinaryUuidType::NAME, BinaryUuidType::class);

        $uuids = [
            '00000000-0000-0000-0000-000000000001',
            '00000000-0000-0000-0000-000000000002',
        ];
        $expectedBinaryValues = \array_map(
            static function (string $uuid): string {
                $binaryValue = \hex2bin(\str_replace('-', '', $uuid));

                if (false === $binaryValue) {
                    throw new FixtureException('the fixture uuid is not valid hexadecimal');
                }

                return $binaryValue;
            },
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

    public function testAttachGenericFiltersBindsUnconvertibleBinaryArrayWithoutType(): void
    {
        $this->registerType(StrictBinaryUuidType::NAME, StrictBinaryUuidType::class);

        $values = [
            '00000000-0000-0000-0000-000000000001',
            'not-a-uuid',
        ];

        $classMetadataMock = Mockery::mock(ClassMetadata::class);
        $classMetadataMock->shouldReceive('getTypeOfField')
            ->with('id')
            ->andReturn(StrictBinaryUuidType::NAME);
        $classMetadataMock->shouldReceive('hasField')
            ->with('id')
            ->andReturn(true);

        $abstractRepositoryMock = $this->mockRepositoryWithManager($classMetadataMock);

        $queryBuilderMock = Mockery::mock(QueryBuilder::class);
        $queryBuilderMock->shouldReceive('andWhere')
            ->andReturnSelf();
        $queryBuilderMock->shouldReceive('setParameter')
            ->with('id', $values, null)
            ->once()
            ->andReturnSelf();

        $reflectionMethod = new ReflectionMethod(AbstractRepository::class, 'attachGenericFilters');
        $reflectionMethod->invoke($abstractRepositoryMock, $queryBuilderMock, ['id' => $values]);
    }

    public function testAttachGenericFiltersBindsIntegerArrayWithoutType(): void
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
            ->with('position', $numbers, null)
            ->once()
            ->andReturnSelf();

        $reflectionMethod = new ReflectionMethod(AbstractRepository::class, 'attachGenericFilters');
        $reflectionMethod->invoke($abstractRepositoryMock, $queryBuilderMock, ['position' => $numbers]);
    }

    public function testAttachGenericFiltersBindsStringArrayWithoutType(): void
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
            ->with('code', $codes, null)
            ->once()
            ->andReturnSelf();

        $reflectionMethod = new ReflectionMethod(AbstractRepository::class, 'attachGenericFilters');
        $reflectionMethod->invoke($abstractRepositoryMock, $queryBuilderMock, ['code' => $codes]);
    }

    public function testAttachGenericFiltersBindsDateStringArrayAsString(): void
    {
        $dates = ['2026-07-04', '2026-07-11'];

        $classMetadataMock = Mockery::mock(ClassMetadata::class);
        $classMetadataMock->shouldReceive('getTypeOfField')
            ->with('date')
            ->andReturn('date');
        $classMetadataMock->shouldReceive('hasField')
            ->with('date')
            ->andReturn(true);

        $abstractRepositoryMock = $this->mockRepositoryWithManager($classMetadataMock);

        $queryBuilderMock = Mockery::mock(QueryBuilder::class);
        $queryBuilderMock->shouldReceive('andWhere')
            ->andReturnSelf();
        $queryBuilderMock->shouldReceive('setParameter')
            ->with('date', $dates, ArrayParameterType::STRING)
            ->once()
            ->andReturnSelf();

        $reflectionMethod = new ReflectionMethod(AbstractRepository::class, 'attachGenericFilters');
        $reflectionMethod->invoke($abstractRepositoryMock, $queryBuilderMock, ['date' => $dates]);
    }

    public function testAttachGenericFiltersConvertsDateTimeArrayThroughFieldType(): void
    {
        $dates = [new DateTime('2026-07-04'), new DateTime('2026-07-11')];

        $classMetadataMock = Mockery::mock(ClassMetadata::class);
        $classMetadataMock->shouldReceive('getTypeOfField')
            ->with('date')
            ->andReturn('date');
        $classMetadataMock->shouldReceive('hasField')
            ->with('date')
            ->andReturn(true);

        $abstractRepositoryMock = $this->mockRepositoryWithManager($classMetadataMock);

        $queryBuilderMock = Mockery::mock(QueryBuilder::class);
        $queryBuilderMock->shouldReceive('andWhere')
            ->andReturnSelf();
        $queryBuilderMock->shouldReceive('setParameter')
            ->with('date', ['2026-07-04', '2026-07-11'], ArrayParameterType::STRING)
            ->once()
            ->andReturnSelf();

        $reflectionMethod = new ReflectionMethod(AbstractRepository::class, 'attachGenericFilters');
        $reflectionMethod->invoke($abstractRepositoryMock, $queryBuilderMock, ['date' => $dates]);
    }

    public function testAttachGenericFiltersConvertsDateTimeImmutableArrayThroughFieldType(): void
    {
        $dates = [new DateTimeImmutable('2026-07-04'), new DateTimeImmutable('2026-07-11')];

        $classMetadataMock = Mockery::mock(ClassMetadata::class);
        $classMetadataMock->shouldReceive('getTypeOfField')
            ->with('date')
            ->andReturn('date');
        $classMetadataMock->shouldReceive('hasField')
            ->with('date')
            ->andReturn(true);

        $abstractRepositoryMock = $this->mockRepositoryWithManager($classMetadataMock);

        $queryBuilderMock = Mockery::mock(QueryBuilder::class);
        $queryBuilderMock->shouldReceive('andWhere')
            ->andReturnSelf();
        $queryBuilderMock->shouldReceive('setParameter')
            ->with('date', ['2026-07-04', '2026-07-11'], ArrayParameterType::STRING)
            ->once()
            ->andReturnSelf();

        $reflectionMethod = new ReflectionMethod(AbstractRepository::class, 'attachGenericFilters');
        $reflectionMethod->invoke($abstractRepositoryMock, $queryBuilderMock, ['date' => $dates]);
    }

    public function testAttachGenericFiltersConvertsMixedStringAndDateTimeArrayThroughFieldType(): void
    {
        $dates = ['2026-07-04', new DateTime('2026-07-11')];

        $classMetadataMock = Mockery::mock(ClassMetadata::class);
        $classMetadataMock->shouldReceive('getTypeOfField')
            ->with('date')
            ->andReturn('date');
        $classMetadataMock->shouldReceive('hasField')
            ->with('date')
            ->andReturn(true);

        $abstractRepositoryMock = $this->mockRepositoryWithManager($classMetadataMock);

        $queryBuilderMock = Mockery::mock(QueryBuilder::class);
        $queryBuilderMock->shouldReceive('andWhere')
            ->andReturnSelf();
        $queryBuilderMock->shouldReceive('setParameter')
            ->with('date', ['2026-07-04', '2026-07-11'], ArrayParameterType::STRING)
            ->once()
            ->andReturnSelf();

        $reflectionMethod = new ReflectionMethod(AbstractRepository::class, 'attachGenericFilters');
        $reflectionMethod->invoke($abstractRepositoryMock, $queryBuilderMock, ['date' => $dates]);
    }

    public function testAttachGenericFiltersBindsDateObjectArrayOnNonDateFieldWithoutType(): void
    {
        $values = [new DateTime('2026-07-04'), new DateTime('2026-07-11')];

        $classMetadataMock = Mockery::mock(ClassMetadata::class);
        $classMetadataMock->shouldReceive('getTypeOfField')
            ->with('payload')
            ->andReturn('string');
        $classMetadataMock->shouldReceive('hasField')
            ->with('payload')
            ->andReturn(true);

        $abstractRepositoryMock = $this->mockRepositoryWithManager($classMetadataMock);

        $queryBuilderMock = Mockery::mock(QueryBuilder::class);
        $queryBuilderMock->shouldReceive('andWhere')
            ->andReturnSelf();
        $queryBuilderMock->shouldReceive('setParameter')
            ->with('payload', $values, null)
            ->once()
            ->andReturnSelf();

        $reflectionMethod = new ReflectionMethod(AbstractRepository::class, 'attachGenericFilters');
        $reflectionMethod->invoke($abstractRepositoryMock, $queryBuilderMock, ['payload' => $values]);
    }

    public function testAttachGenericFiltersConvertsUuidObjectArrayThroughFieldType(): void
    {
        $this->registerType(UuidType::NAME, UuidType::class);

        $uuids = [
            Uuid::fromString('00000000-0000-0000-0000-000000000001'),
            Uuid::fromString('00000000-0000-0000-0000-000000000002'),
        ];
        $expectedDatabaseValues = \array_map(
            static fn(Uuid $uuid): string => $uuid->toBinary(),
            $uuids,
        );

        $classMetadataMock = Mockery::mock(ClassMetadata::class);
        $classMetadataMock->shouldReceive('getTypeOfField')
            ->with('id')
            ->andReturn(UuidType::NAME);
        $classMetadataMock->shouldReceive('hasField')
            ->with('id')
            ->andReturn(true);

        $abstractRepositoryMock = $this->mockRepositoryWithManager($classMetadataMock);

        $queryBuilderMock = Mockery::mock(QueryBuilder::class);
        $queryBuilderMock->shouldReceive('andWhere')
            ->andReturnSelf();
        $queryBuilderMock->shouldReceive('setParameter')
            ->with('id', $expectedDatabaseValues, ArrayParameterType::STRING)
            ->once()
            ->andReturnSelf();

        $reflectionMethod = new ReflectionMethod(AbstractRepository::class, 'attachGenericFilters');
        $reflectionMethod->invoke($abstractRepositoryMock, $queryBuilderMock, ['id' => $uuids]);
    }

    public function testAttachGenericFiltersConvertsUuidStringArrayThroughFieldType(): void
    {
        $this->registerType(UuidType::NAME, UuidType::class);

        $uuids = [
            '00000000-0000-0000-0000-000000000001',
            '00000000-0000-0000-0000-000000000002',
        ];
        $expectedDatabaseValues = \array_map(
            static fn(string $uuid): string => Uuid::fromString($uuid)->toBinary(),
            $uuids,
        );

        $classMetadataMock = Mockery::mock(ClassMetadata::class);
        $classMetadataMock->shouldReceive('getTypeOfField')
            ->with('id')
            ->andReturn(UuidType::NAME);
        $classMetadataMock->shouldReceive('hasField')
            ->with('id')
            ->andReturn(true);

        $abstractRepositoryMock = $this->mockRepositoryWithManager($classMetadataMock);

        $queryBuilderMock = Mockery::mock(QueryBuilder::class);
        $queryBuilderMock->shouldReceive('andWhere')
            ->andReturnSelf();
        $queryBuilderMock->shouldReceive('setParameter')
            ->with('id', $expectedDatabaseValues, ArrayParameterType::STRING)
            ->once()
            ->andReturnSelf();

        $reflectionMethod = new ReflectionMethod(AbstractRepository::class, 'attachGenericFilters');
        $reflectionMethod->invoke($abstractRepositoryMock, $queryBuilderMock, ['id' => $uuids]);
    }

    public function testAttachGenericFiltersConvertsMixedUuidAndStringArrayThroughFieldType(): void
    {
        $this->registerType(UuidType::NAME, UuidType::class);

        $uuids = [
            Uuid::fromString('00000000-0000-0000-0000-000000000001'),
            '00000000-0000-0000-0000-000000000002',
        ];
        $expectedDatabaseValues = [
            Uuid::fromString('00000000-0000-0000-0000-000000000001')->toBinary(),
            Uuid::fromString('00000000-0000-0000-0000-000000000002')->toBinary(),
        ];

        $classMetadataMock = Mockery::mock(ClassMetadata::class);
        $classMetadataMock->shouldReceive('getTypeOfField')
            ->with('id')
            ->andReturn(UuidType::NAME);
        $classMetadataMock->shouldReceive('hasField')
            ->with('id')
            ->andReturn(true);

        $abstractRepositoryMock = $this->mockRepositoryWithManager($classMetadataMock);

        $queryBuilderMock = Mockery::mock(QueryBuilder::class);
        $queryBuilderMock->shouldReceive('andWhere')
            ->andReturnSelf();
        $queryBuilderMock->shouldReceive('setParameter')
            ->with('id', $expectedDatabaseValues, ArrayParameterType::STRING)
            ->once()
            ->andReturnSelf();

        $reflectionMethod = new ReflectionMethod(AbstractRepository::class, 'attachGenericFilters');
        $reflectionMethod->invoke($abstractRepositoryMock, $queryBuilderMock, ['id' => $uuids]);
    }

    public function testAttachGenericFiltersBindsUnconvertibleUuidArrayWithoutType(): void
    {
        $this->registerType(UuidType::NAME, UuidType::class);

        $values = [
            '00000000-0000-0000-0000-000000000001',
            'not-a-uuid',
        ];

        $classMetadataMock = Mockery::mock(ClassMetadata::class);
        $classMetadataMock->shouldReceive('getTypeOfField')
            ->with('id')
            ->andReturn(UuidType::NAME);
        $classMetadataMock->shouldReceive('hasField')
            ->with('id')
            ->andReturn(true);

        $abstractRepositoryMock = $this->mockRepositoryWithManager($classMetadataMock);

        $queryBuilderMock = Mockery::mock(QueryBuilder::class);
        $queryBuilderMock->shouldReceive('andWhere')
            ->andReturnSelf();
        $queryBuilderMock->shouldReceive('setParameter')
            ->with('id', $values, null)
            ->once()
            ->andReturnSelf();

        $reflectionMethod = new ReflectionMethod(AbstractRepository::class, 'attachGenericFilters');
        $reflectionMethod->invoke($abstractRepositoryMock, $queryBuilderMock, ['id' => $values]);
    }

    public function testAttachGenericFiltersBindsArrayWithoutTypeWhenUidTypeLacksGetName(): void
    {
        $this->registerType(CustomUidType::NAME, CustomUidType::class);

        $values = ['not-a-uuid'];

        $classMetadataMock = Mockery::mock(ClassMetadata::class);
        $classMetadataMock->shouldReceive('getTypeOfField')
            ->with('id')
            ->andReturn(CustomUidType::NAME);
        $classMetadataMock->shouldReceive('hasField')
            ->with('id')
            ->andReturn(true);

        $abstractRepositoryMock = $this->mockRepositoryWithManager($classMetadataMock);

        $queryBuilderMock = Mockery::mock(QueryBuilder::class);
        $queryBuilderMock->shouldReceive('andWhere')
            ->andReturnSelf();
        $queryBuilderMock->shouldReceive('setParameter')
            ->with('id', $values, null)
            ->once()
            ->andReturnSelf();

        $reflectionMethod = new ReflectionMethod(AbstractRepository::class, 'attachGenericFilters');
        $reflectionMethod->invoke($abstractRepositoryMock, $queryBuilderMock, ['id' => $values]);
    }

    public function testAttachGenericFiltersRethrowsErrorWhenUidTypeDeclaresGetName(): void
    {
        $this->registerType(BrokenUidType::NAME, BrokenUidType::class);

        $values = ['00000000-0000-0000-0000-000000000001'];

        $classMetadataMock = Mockery::mock(ClassMetadata::class);
        $classMetadataMock->shouldReceive('getTypeOfField')
            ->with('id')
            ->andReturn(BrokenUidType::NAME);
        $classMetadataMock->shouldReceive('hasField')
            ->with('id')
            ->andReturn(true);

        $abstractRepositoryMock = $this->mockRepositoryWithManager($classMetadataMock);

        $queryBuilderMock = Mockery::mock(QueryBuilder::class);
        $queryBuilderMock->shouldReceive('andWhere')
            ->andReturnSelf();

        $this->expectException(Error::class);
        $this->expectExceptionMessage('broken uid type conversion');

        $reflectionMethod = new ReflectionMethod(AbstractRepository::class, 'attachGenericFilters');
        $reflectionMethod->invoke($abstractRepositoryMock, $queryBuilderMock, ['id' => $values]);
    }

    public function testAttachGenericFiltersBindsNonDateObjectArrayWithoutType(): void
    {
        $values = [new stdClass(), new stdClass()];

        $classMetadataMock = Mockery::mock(ClassMetadata::class);
        $classMetadataMock->shouldReceive('getTypeOfField')
            ->with('payload')
            ->andReturn('string');
        $classMetadataMock->shouldReceive('hasField')
            ->with('payload')
            ->andReturn(true);

        $abstractRepositoryMock = $this->mockRepositoryWithManager($classMetadataMock);

        $queryBuilderMock = Mockery::mock(QueryBuilder::class);
        $queryBuilderMock->shouldReceive('andWhere')
            ->andReturnSelf();
        $queryBuilderMock->shouldReceive('setParameter')
            ->with('payload', $values, null)
            ->once()
            ->andReturnSelf();

        $reflectionMethod = new ReflectionMethod(AbstractRepository::class, 'attachGenericFilters');
        $reflectionMethod->invoke($abstractRepositoryMock, $queryBuilderMock, ['payload' => $values]);
    }

    public function testAttachGenericFiltersBindsIntBackedEnumArrayWithoutType(): void
    {
        $statuses = [IntBackedEnum::First, IntBackedEnum::Second];

        $classMetadataMock = Mockery::mock(ClassMetadata::class);
        $classMetadataMock->shouldReceive('getTypeOfField')
            ->with('status')
            ->andReturn('smallint');
        $classMetadataMock->shouldReceive('hasField')
            ->with('status')
            ->andReturn(true);

        $abstractRepositoryMock = $this->mockRepositoryWithManager($classMetadataMock);

        $queryBuilderMock = Mockery::mock(QueryBuilder::class);
        $queryBuilderMock->shouldReceive('andWhere')
            ->andReturnSelf();
        $queryBuilderMock->shouldReceive('setParameter')
            ->with('status', $statuses, null)
            ->once()
            ->andReturnSelf();

        $reflectionMethod = new ReflectionMethod(AbstractRepository::class, 'attachGenericFilters');
        $reflectionMethod->invoke($abstractRepositoryMock, $queryBuilderMock, ['status' => $statuses]);
    }

    public function testAttachGenericFiltersBindsStringBackedEnumArrayWithoutType(): void
    {
        $kinds = [StringBackedEnum::Alpha, StringBackedEnum::Beta];

        $classMetadataMock = Mockery::mock(ClassMetadata::class);
        $classMetadataMock->shouldReceive('getTypeOfField')
            ->with('kind')
            ->andReturn('string');
        $classMetadataMock->shouldReceive('hasField')
            ->with('kind')
            ->andReturn(true);

        $abstractRepositoryMock = $this->mockRepositoryWithManager($classMetadataMock);

        $queryBuilderMock = Mockery::mock(QueryBuilder::class);
        $queryBuilderMock->shouldReceive('andWhere')
            ->andReturnSelf();
        $queryBuilderMock->shouldReceive('setParameter')
            ->with('kind', $kinds, null)
            ->once()
            ->andReturnSelf();

        $reflectionMethod = new ReflectionMethod(AbstractRepository::class, 'attachGenericFilters');
        $reflectionMethod->invoke($abstractRepositoryMock, $queryBuilderMock, ['kind' => $kinds]);
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

        $queryBuilderMock->shouldReceive('setParameter')
            ->with('owner', $ownerValues, null)
            ->once()
            ->andReturnSelf();

        $reflectionMethod = new ReflectionMethod(AbstractRepository::class, 'attachGenericFilters');
        $reflectionMethod->invoke($abstractRepositoryMock, $queryBuilderMock, ['owner' => $ownerValues]);
    }

    public function testAttachGenericFiltersBindsSingleValuesWithoutTypeWhenManagerIsNotOrm(): void
    {
        $abstractRepositoryMock = $this->get(AbstractRepository::class);
        $abstractRepositoryMock->shouldAllowMockingProtectedMethods();
        $abstractRepositoryMock->shouldReceive('getEntityClass')
            ->andReturn('Entity');

        $managerRegistryMock = Mockery::mock(ManagerRegistry::class);
        $managerRegistryMock->shouldReceive('getManagerForClass')
            ->with('Entity')
            ->andReturn(Mockery::mock(ObjectManager::class));

        $abstractRepositoryMock->setManagerRegistry($managerRegistryMock);

        $uuid = '00000000-0000-0000-0000-000000000009';

        $queryBuilderMock = Mockery::mock(QueryBuilder::class);
        $queryBuilderMock->shouldReceive('andWhere')
            ->andReturnSelf();

        $queryBuilderMock->shouldReceive('setParameter')
            ->with('id', $uuid, null)
            ->once()
            ->andReturnSelf();
        $queryBuilderMock->shouldReceive('setParameter')
            ->with('position', 5, null)
            ->once()
            ->andReturnSelf();
        $queryBuilderMock->shouldReceive('setParameter')
            ->with('code', 'active', null)
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

        $queryBuilderMock->shouldReceive('setParameter')
            ->with('id', $ids, null)
            ->once()
            ->andReturnSelf();

        $reflectionMethod = new ReflectionMethod(AbstractRepository::class, 'attachGenericFilters');
        $reflectionMethod->invoke($abstractRepositoryMock, $queryBuilderMock, ['id' => $ids]);
    }

    public function testAttachFiltersDispatchesBothFilterKindsAndAttachesTheJoins(): void
    {
        $reflectionMethod = new ReflectionMethod(AbstractRepository::class, 'attachFilters');

        $abstractRepositoryMock = $this->get(AbstractRepository::class);
        $abstractRepositoryMock->shouldAllowMockingProtectedMethods();

        $queryBuilderMock = Mockery::mock(QueryBuilder::class);

        $genericFilters = ['name' => 'test'];
        $customFilters = ['customField' => 'value'];

        $joinCollection = new JoinCollection();
        $joinCollection->addJoin(new Join(Join::INNER_JOIN, 'test', 't'));

        $abstractRepositoryMock->shouldReceive('sortFilters')
            ->once()
            ->andReturn([$genericFilters, $customFilters]);
        $abstractRepositoryMock->shouldReceive('attachGenericFilters')
            ->once()
            ->with($queryBuilderMock, $genericFilters);
        $abstractRepositoryMock->shouldReceive('attachCustomFilters')
            ->once()
            ->with($queryBuilderMock, $customFilters)
            ->andReturn($joinCollection);
        $abstractRepositoryMock->shouldReceive('attachJoins')
            ->once()
            ->with($queryBuilderMock, $joinCollection);

        $result = $reflectionMethod->invoke(
            $abstractRepositoryMock,
            $queryBuilderMock,
            $genericFilters + $customFilters,
        );

        static::assertSame($joinCollection, $result);
    }

    public function testAttachFiltersSkipsEachStepWhenItsFilterListIsEmpty(): void
    {
        $reflectionMethod = new ReflectionMethod(AbstractRepository::class, 'attachFilters');

        $abstractRepositoryMock = $this->get(AbstractRepository::class);
        $abstractRepositoryMock->shouldAllowMockingProtectedMethods();

        $queryBuilderMock = Mockery::mock(QueryBuilder::class);

        $abstractRepositoryMock->shouldReceive('sortFilters')
            ->once()
            ->andReturn([[], []]);
        $abstractRepositoryMock->shouldNotReceive('attachGenericFilters');
        $abstractRepositoryMock->shouldNotReceive('attachCustomFilters');
        $abstractRepositoryMock->shouldNotReceive('attachJoins');

        static::assertNull($reflectionMethod->invoke($abstractRepositoryMock, $queryBuilderMock, []));
    }

    public function testCreateQueryBuilderFromCriteriaThrowsOnUnmappedFilterField(): void
    {
        $abstractRepositoryMock = $this->mockCriteriaRepository(Mockery::mock(QueryBuilder::class), ['missing' => false]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('criteria field `missing` is not mapped');

        $this->invokeCriteria($abstractRepositoryMock, new Criteria(
            filters: [new Filter('missing', Operator::Equal, 'value')],
        ));
    }

    public function testCreateQueryBuilderFromCriteriaThrowsOnUnmappedSortField(): void
    {
        $abstractRepositoryMock = $this->mockCriteriaRepository(Mockery::mock(QueryBuilder::class), ['missing' => false]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('sort field `missing` is not mapped');

        $this->invokeCriteria($abstractRepositoryMock, new Criteria(sorts: [new Sort('missing')]));
    }

    public function testCreateQueryBuilderFromCriteriaThrowsWhenAnInOperatorGetsNoArray(): void
    {
        $abstractRepositoryMock = $this->mockCriteriaRepository(Mockery::mock(QueryBuilder::class), ['label' => true]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('criteria operator `IN` requires an array value');

        $this->invokeCriteria($abstractRepositoryMock, new Criteria(
            filters: [new Filter('label', Operator::In, 'not-an-array')],
        ));
    }

    public function testCreateQueryBuilderFromCriteriaForcesAnEmptyInFilterToMatchNothing(): void
    {
        $queryBuilderMock = Mockery::mock(QueryBuilder::class);
        $queryBuilderMock->shouldReceive('andWhere')
            ->with("'label' = 'label-emptyFilter'")
            ->once()
            ->andReturnSelf();

        $abstractRepositoryMock = $this->mockCriteriaRepository($queryBuilderMock, ['label' => true]);

        $this->invokeCriteria($abstractRepositoryMock, new Criteria(
            filters: [new Filter('label', Operator::In, [])],
        ));
    }

    public function testCreateQueryBuilderFromCriteriaLeavesAnEmptyNotInFilterUnconstrained(): void
    {
        $queryBuilderMock = Mockery::mock(QueryBuilder::class);
        $queryBuilderMock->shouldNotReceive('andWhere');

        $abstractRepositoryMock = $this->mockCriteriaRepository($queryBuilderMock, ['label' => true]);

        $this->invokeCriteria($abstractRepositoryMock, new Criteria(
            filters: [new Filter('label', Operator::NotIn, [])],
        ));
    }

    public function testCreateQueryBuilderFromCriteriaThrowsOnAnEmptyNotInFilterWhenTheFlagSaysSo(): void
    {
        $abstractRepositoryMock = $this->mockCriteriaRepository(Mockery::mock(QueryBuilder::class), ['label' => true]);
        $abstractRepositoryMock->shouldReceive('getFlags')
            ->andReturn([EmptyArrayFilterBehavior::class => EmptyArrayFilterBehavior::ThrowException]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('expected non-empty array, got empty array');

        $this->invokeCriteria($abstractRepositoryMock, new Criteria(
            filters: [new Filter('label', Operator::NotIn, [])],
        ));
    }

    public function testCreateQueryBuilderFromCriteriaThrowsWhenAScalarOperatorGetsAnArray(): void
    {
        $abstractRepositoryMock = $this->mockCriteriaRepository(Mockery::mock(QueryBuilder::class), ['label' => true]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('criteria operator `=` requires a single value');

        $this->invokeCriteria($abstractRepositoryMock, new Criteria(
            filters: [new Filter('label', Operator::Equal, ['alpha', 'beta'])],
        ));
    }

    public function testCreateQueryBuilderFromCriteriaThrowsWhenKeysetHasNoSort(): void
    {
        $abstractRepositoryMock = $this->mockCriteriaRepository(Mockery::mock(QueryBuilder::class), []);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('keyset pagination requires at least one sort');

        $this->invokeCriteria($abstractRepositoryMock, new Criteria(keyset: new Keyset(['id' => 1])));
    }

    public function testCreateQueryBuilderFromCriteriaThrowsWhenAKeysetValueIsMissing(): void
    {
        $queryBuilderMock = Mockery::mock(QueryBuilder::class);
        $queryBuilderMock->shouldReceive('addOrderBy')->andReturnSelf();

        $abstractRepositoryMock = $this->mockCriteriaRepository($queryBuilderMock, ['id' => true]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('keyset value for `id` is missing');

        $this->invokeCriteria($abstractRepositoryMock, new Criteria(
            sorts: [new Sort('id')],
            keyset: new Keyset(['label' => 'alpha']),
        ));
    }

    public function testCreateQueryBuilderFromCriteriaThrowsWhenAKeysetValueIsNull(): void
    {
        $queryBuilderMock = Mockery::mock(QueryBuilder::class);
        $queryBuilderMock->shouldReceive('addOrderBy')->andReturnSelf();

        $abstractRepositoryMock = $this->mockCriteriaRepository($queryBuilderMock, ['id' => true]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('keyset value for `id` must not be null');

        $this->invokeCriteria($abstractRepositoryMock, new Criteria(
            sorts: [new Sort('id')],
            keyset: new Keyset(['id' => null]),
        ));
    }

    public function testCreateQueryBuilderFromCriteriaThrowsOnANonPositiveLimit(): void
    {
        $abstractRepositoryMock = $this->mockCriteriaRepository(Mockery::mock(QueryBuilder::class), []);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('criteria limit must be positive');

        $this->invokeCriteria($abstractRepositoryMock, new Criteria(limit: 0));
    }

    public function testCreateQueryBuilderFromCriteriaEmitsANullCheckWithoutBindingAParameter(): void
    {
        $queryBuilderMock = Mockery::mock(QueryBuilder::class);
        $queryBuilderMock->shouldReceive('andWhere')
            ->with(Mockery::on(static fn(string $clause): bool => \str_ends_with($clause, '.label IS NULL')))
            ->once()
            ->andReturnSelf();
        $queryBuilderMock->shouldNotReceive('setParameter');

        $abstractRepositoryMock = $this->mockCriteriaRepository($queryBuilderMock, ['label' => true]);

        $this->invokeCriteria($abstractRepositoryMock, new Criteria(
            filters: [new Filter('label', Operator::IsNull)],
        ));
    }

    public function testCreateQueryBuilderFromCriteriaRejectsANullableSortFieldUnderAKeyset(): void
    {
        $queryBuilderMock = Mockery::mock(QueryBuilder::class);
        $queryBuilderMock->shouldNotReceive('addOrderBy');

        $abstractRepositoryMock = $this->mockCriteriaRepository($queryBuilderMock, ['deletedAt' => true], ['deletedAt']);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('keyset sort field `deletedAt` is nullable; a row holding null is never reached by a keyset comparison');

        $this->invokeCriteria($abstractRepositoryMock, new Criteria(
            sorts: [new Sort('deletedAt')],
            keyset: new Keyset(['deletedAt' => '2026-01-01']),
        ));
    }

    public function testCreateQueryBuilderFromCriteriaSortsOnANullableFieldWithoutAKeyset(): void
    {
        $queryBuilderMock = Mockery::mock(QueryBuilder::class);
        $queryBuilderMock->shouldReceive('addOrderBy')
            ->with(Mockery::on(static fn(string $sort): bool => \str_ends_with($sort, '.deletedAt')), 'ASC')
            ->once()
            ->andReturnSelf();

        $abstractRepositoryMock = $this->mockCriteriaRepository($queryBuilderMock, ['deletedAt' => true], ['deletedAt']);

        $this->invokeCriteria($abstractRepositoryMock, new Criteria(sorts: [new Sort('deletedAt')]));
    }

    public function testCreateQueryBuilderFromCriteriaRejectsALikeOnAnAssociation(): void
    {
        $queryBuilderMock = Mockery::mock(QueryBuilder::class);
        $queryBuilderMock->shouldNotReceive('andWhere');

        $abstractRepositoryMock = $this->mockCriteriaRepository($queryBuilderMock, ['owner' => true], [], ['owner']);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('criteria operator `LIKE` cannot apply to the association `owner`');

        $this->invokeCriteria($abstractRepositoryMock, new Criteria(
            filters: [new Filter('owner', Operator::Like, '%x%')],
        ));
    }

    public function testCreateQueryBuilderFromCriteriaConvertsAScalarValueThroughTheFieldType(): void
    {
        $this->registerType(UuidType::NAME, UuidType::class);

        $uuid = Uuid::fromString('00000000-0000-0000-0000-000000000001');

        $classMetadataMock = Mockery::mock(ClassMetadata::class);
        $classMetadataMock->shouldReceive('getTypeOfField')
            ->with('id')
            ->andReturn(UuidType::NAME);
        $classMetadataMock->shouldReceive('hasField')
            ->with('id')
            ->andReturn(true);

        $abstractRepositoryMock = $this->mockRepositoryWithManager($classMetadataMock);

        $queryBuilderMock = Mockery::mock(QueryBuilder::class);
        $queryBuilderMock->shouldReceive('andWhere')
            ->andReturnSelf();
        $queryBuilderMock->shouldReceive('setParameter')
            ->with('criteria_0', $uuid->toBinary(), ParameterType::STRING)
            ->once()
            ->andReturnSelf();

        $this->mockCriteriaLookups($abstractRepositoryMock, $queryBuilderMock, ['id' => true]);

        $this->invokeCriteria($abstractRepositoryMock, new Criteria(
            filters: [new Filter('id', Operator::GreaterThan, $uuid)],
        ));
    }

    public function testCreateQueryBuilderFromCriteriaConvertsAKeysetValueThroughTheFieldType(): void
    {
        $this->registerType(UuidType::NAME, UuidType::class);

        $uuid = Uuid::fromString('00000000-0000-0000-0000-000000000002');

        $classMetadataMock = Mockery::mock(ClassMetadata::class);
        $classMetadataMock->shouldReceive('getTypeOfField')
            ->with('id')
            ->andReturn(UuidType::NAME);
        $classMetadataMock->shouldReceive('hasField')
            ->with('id')
            ->andReturn(true);

        $abstractRepositoryMock = $this->mockRepositoryWithManager($classMetadataMock);

        $queryBuilderMock = Mockery::mock(QueryBuilder::class);
        $queryBuilderMock->shouldReceive('addOrderBy', 'andWhere')
            ->andReturnSelf();
        $queryBuilderMock->shouldReceive('setParameter')
            ->with('keyset_0', $uuid->toBinary(), ParameterType::STRING)
            ->once()
            ->andReturnSelf();

        $this->mockCriteriaLookups($abstractRepositoryMock, $queryBuilderMock, ['id' => true]);

        $this->invokeCriteria($abstractRepositoryMock, new Criteria(
            sorts: [new Sort('id')],
            keyset: new Keyset(['id' => $uuid]),
        ));
    }

    public function testConvertScalarFilterValueMapsEveryListParameterTypeToItsElementType(): void
    {
        $abstractRepositoryMock = $this->get(AbstractRepository::class);
        $abstractRepositoryMock->shouldAllowMockingProtectedMethods();

        $listTypes = [
            [ArrayParameterType::BINARY, ParameterType::BINARY],
            [ArrayParameterType::STRING, ParameterType::STRING],
            [ArrayParameterType::INTEGER, ParameterType::INTEGER],
            [ArrayParameterType::ASCII, ParameterType::ASCII],
            [null, null],
        ];

        foreach ($listTypes as [$arrayParameterType, $parameterType]) {
            $abstractRepositoryMock->shouldReceive('convertArrayFilterValues')
                ->with('field', ['raw'])
                ->once()
                ->andReturn([['converted'], $arrayParameterType]);

            static::assertSame(
                ['converted', $parameterType],
                (new ReflectionMethod(AbstractRepository::class, 'convertScalarFilterValue'))
                    ->invoke($abstractRepositoryMock, 'field', 'raw'),
            );
        }

        $abstractRepositoryMock->shouldNotReceive('convertArrayFilterValues')
            ->with('field', [null]);

        static::assertSame(
            [null, null],
            (new ReflectionMethod(AbstractRepository::class, 'convertScalarFilterValue'))->invoke($abstractRepositoryMock, 'field', null),
        );
    }

    public function testAttachGenericFiltersBindsAScalarUntypedWhenNoRegistryWasSet(): void
    {
        /* a consumer's repository test: a partial double with `getDoctrineRepository()` stubbed and no registry ever set */
        $abstractRepositoryMock = $this->get(AbstractRepository::class);
        $abstractRepositoryMock->shouldAllowMockingProtectedMethods();

        $queryBuilderMock = Mockery::mock(QueryBuilder::class);
        $queryBuilderMock->shouldReceive('andWhere')
            ->andReturnSelf();
        $queryBuilderMock->shouldReceive('setParameter')
            ->with('pullId', 7, null)
            ->once()
            ->andReturnSelf();

        $reflectionMethod = new ReflectionMethod(AbstractRepository::class, 'attachGenericFilters');
        $reflectionMethod->invoke($abstractRepositoryMock, $queryBuilderMock, ['pullId' => 7]);
    }

    public function testAttachGenericFiltersConvertsAScalarUuidThroughFieldType(): void
    {
        $this->registerType(UuidType::NAME, UuidType::class);

        $uuid = Uuid::fromString('00000000-0000-0000-0000-000000000003');

        $classMetadataMock = Mockery::mock(ClassMetadata::class);
        $classMetadataMock->shouldReceive('getTypeOfField')
            ->with('id')
            ->andReturn(UuidType::NAME);
        $classMetadataMock->shouldReceive('hasField')
            ->with('id')
            ->andReturn(true);

        $abstractRepositoryMock = $this->mockRepositoryWithManager($classMetadataMock);

        $queryBuilderMock = Mockery::mock(QueryBuilder::class);
        $queryBuilderMock->shouldReceive('andWhere')
            ->andReturnSelf();
        $queryBuilderMock->shouldReceive('setParameter')
            ->with('id', $uuid->toBinary(), ParameterType::STRING)
            ->once()
            ->andReturnSelf();

        $reflectionMethod = new ReflectionMethod(AbstractRepository::class, 'attachGenericFilters');
        $reflectionMethod->invoke($abstractRepositoryMock, $queryBuilderMock, ['id' => $uuid]);
    }

    /** @param class-string<Type> $typeClass */
    private function registerType(string $name, string $typeClass): void
    {
        if (false === Type::hasType($name)) {
            Type::addType($name, $typeClass);
        }
    }

    /**
     * @param ClassMetadata<object>&MockInterface $classMetadataMock
     *
     * @return AbstractRepository&MockInterface
     */
    private function mockRepositoryWithManager(ClassMetadata&MockInterface $classMetadataMock): MockInterface
    {
        $abstractRepositoryMock = $this->get(AbstractRepository::class);
        $abstractRepositoryMock->shouldAllowMockingProtectedMethods();
        $abstractRepositoryMock->shouldReceive('getEntityClass')
            ->andReturn('Entity');

        $platformMock = Mockery::mock(AbstractPlatform::class);
        $platformMock->shouldReceive('getDateFormatString')
            ->andReturn('Y-m-d');

        /* the two declarations must stay identical: that is how AbstractUidType concludes there is no native GUID type, as on MySQL */
        $platformMock->shouldReceive('getGuidTypeDeclarationSQL')
            ->andReturn('CHAR(36)');
        $platformMock->shouldReceive('getStringTypeDeclarationSQL')
            ->andReturn('CHAR(36)');

        $connectionMock = Mockery::mock(Connection::class);
        $connectionMock->shouldReceive('getDatabasePlatform')
            ->andReturn($platformMock);

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

    private function mockAbstractRepository(AbstractRepository&MockInterface $abstractRepositoryMock): void
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
        /* a scalar filter asks the conversion pipeline, which steps aside without an ORM manager */
        $managerRegistryMock->shouldReceive('getManagerForClass')
            ->byDefault()
            ->andReturn(Mockery::mock(ObjectManager::class));

        $abstractRepositoryMock->setManagerRegistry($managerRegistryMock);

        /* twice for the repository lookups, once for the one generic scalar filter the conversion pipeline sees */
        $abstractRepositoryMock->shouldReceive('getEntityClass')
            ->times(3)
            ->andReturn('test');

        $abstractRepositoryMock->shouldReceive('attachCustomFilters')
            ->once()
            ->andReturn(
                (new JoinCollection())->addJoin(
                    new Join(Join::INNER_JOIN, 'test', 't'),
                ),
            );
    }

    /**
     * @param array<string, bool> $mappedFields
     * @param list<string> $nullableFields
     * @param list<string> $associations
     */
    private function mockCriteriaRepository(
        QueryBuilder $queryBuilder,
        array $mappedFields,
        array $nullableFields = [],
        array $associations = [],
    ): AbstractRepository&MockInterface {
        $abstractRepositoryMock = $this->get(AbstractRepository::class);
        $abstractRepositoryMock->shouldAllowMockingProtectedMethods();
        $abstractRepositoryMock->shouldReceive('convertArrayFilterValues')
            ->byDefault()
            ->andReturnUsing(static fn(string $filterName, array $filterValue): array => [$filterValue, null]);

        $this->mockCriteriaLookups($abstractRepositoryMock, $queryBuilder, $mappedFields, $nullableFields, $associations);

        return $abstractRepositoryMock;
    }

    /**
     * @param array<string, bool> $mappedFields
     * @param list<string> $nullableFields
     * @param list<string> $associations
     */
    private function mockCriteriaLookups(
        AbstractRepository&MockInterface $abstractRepositoryMock,
        QueryBuilder $queryBuilder,
        array $mappedFields,
        array $nullableFields = [],
        array $associations = [],
    ): void {
        $abstractRepositoryMock->shouldReceive('createQueryBuilder')
            ->andReturn($queryBuilder);

        $doctrineRepositoryMock = Mockery::mock(DoctrineRepository::class);

        foreach ($mappedFields as $fieldName => $isMapped) {
            $doctrineRepositoryMock->shouldReceive('hasField')
                ->with($fieldName)
                ->andReturn($isMapped);
        }

        $doctrineRepositoryMock->shouldReceive('allowsNull')
            ->andReturnUsing(static fn(string $fieldName): bool => true === \in_array($fieldName, $nullableFields, true));
        $doctrineRepositoryMock->shouldReceive('hasAssociation')
            ->andReturnUsing(static fn(string $fieldName): bool => true === \in_array($fieldName, $associations, true));

        $abstractRepositoryMock->shouldReceive('getDoctrineRepository')
            ->andReturn($doctrineRepositoryMock);
    }

    private function invokeCriteria(AbstractRepository $abstractRepository, Criteria $criteria): void
    {
        (new ReflectionMethod(AbstractRepository::class, 'createQueryBuilderFromCriteria'))
            ->invoke($abstractRepository, $criteria);
    }
}
