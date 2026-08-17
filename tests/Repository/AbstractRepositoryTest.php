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
use PrecisionSoft\Doctrine\Utility\Repository\DoctrineRepository;
use PrecisionSoft\Doctrine\Utility\Repository\EmptyArrayFilterBehavior;
use PrecisionSoft\Doctrine\Utility\Test\Repository\Fixture\BinaryUuidType;
use PrecisionSoft\Doctrine\Utility\Test\Repository\Fixture\BrokenUidType;
use PrecisionSoft\Doctrine\Utility\Test\Repository\Fixture\CustomUidType;
use PrecisionSoft\Doctrine\Utility\Test\Repository\Fixture\IntBackedEnum;
use PrecisionSoft\Doctrine\Utility\Test\Repository\Fixture\StrictBinaryUuidType;
use PrecisionSoft\Doctrine\Utility\Test\Repository\Fixture\StringBackedEnum;
use PrecisionSoft\Symfony\Phpunit\MockDto;
use PrecisionSoft\Symfony\Phpunit\TestCase\AbstractTestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
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
        $this->registerType(BinaryUuidType::NAME, BinaryUuidType::class);

        $uuids = [
            '00000000-0000-0000-0000-000000000001',
            '00000000-0000-0000-0000-000000000002',
        ];
        $expectedBinaryValues = \array_map(
            static function (string $uuid): string {
                $binaryValue = \hex2bin(\str_replace('-', '', $uuid));

                if (false === $binaryValue) {
                    throw new RuntimeException('the fixture uuid is not valid hexadecimal');
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
            ->with('id', $values)
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
            ->with('position', $numbers)
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
            ->with('code', $codes)
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
            ->with('payload', $values)
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
            ->with('id', $values)
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
            ->with('id', $values)
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
            ->with('payload', $values)
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
            ->with('status', $statuses)
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
            ->with('kind', $kinds)
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

        $queryBuilderMock->shouldReceive('setParameter')
            ->with('id', $ids)
            ->once()
            ->andReturnSelf();

        $reflectionMethod = new ReflectionMethod(AbstractRepository::class, 'attachGenericFilters');
        $reflectionMethod->invoke($abstractRepositoryMock, $queryBuilderMock, ['id' => $ids]);
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
}
