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
use PrecisionSoft\Doctrine\Utility\Test\Repository\Fixture\StringBackedEnum;
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
        $this->registerType(BinaryUuidType::NAME, BinaryUuidType::class);

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

        /** @info a non-binary mapped field keeps the untyped binding — Doctrine infers the array type as it did before, setParameter() is called with two arguments only */
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
        /** @info a date column is selected by its Doctrine type, so raw 'YYYY-MM-DD' strings take the date branch too — they are already bindable and pass through untouched, bound as ArrayParameterType::STRING (identical to the type Doctrine inferred for an untyped string array) */
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
        /** @info regression: a DateTime[] IN filter must be converted through the field type and bound as ArrayParameterType::STRING — DBAL has no array parameter type for dates, so an untyped DateTime would bind as a string and fail to stringify */
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
        /** @info a date field filter must accept both raw strings and date objects in the same array — the string is left untouched while the DateTime is converted through the field type, and both are bound as ArrayParameterType::STRING */
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
        /** @info a string column is not a date/time column, so date objects passed to it never take the date branch — the filter keeps the untyped two-argument binding, exactly as Doctrine resolved it before */
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

        /** @info regression: a Uuid[] IN filter must be converted through the field type and bound as ArrayParameterType::STRING — the uid type binds as STRING so it misses the binary branch, yet the column is stored as BINARY(16), and an untyped AbstractUid would reach the driver as its 36-character string and silently match nothing */
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

        /** @info a uid field filter must keep accepting raw RFC 4122 strings — the uid type converts them to the same binary representation as AbstractUid objects, so existing callers passing strings stay correct */
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

        /** @info a uid field filter must accept both AbstractUid objects and raw RFC 4122 strings in the same array — the uid type converts both to the column's binary representation */
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

        /** @info an element the uid type rejects makes the whole filter fall back to the untyped two-argument binding — never binds worse than before the uid handling was added */
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

        /** @info a consumer AbstractUidType subclass without getName() (valid under DBAL 4) raises Error instead of ConversionException when it rejects an element — the fallback must still bind the original array untyped instead of crashing */
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

        /** @info a uid type that declares getName() can only raise Error through a genuine bug in its own overrides — the filter must rethrow it instead of silently binding untyped and matching nothing */
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
        /** @info a custom-typed object array carries no date/time object, so it keeps the pre-existing untyped binding — setParameter() is called with two arguments only, exactly as Doctrine resolved it before */
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
        /** @info a smallint field binds INTEGER, not BINARY, so the enum array keeps the untyped binding — Doctrine unwraps the backed enums to their scalar backing at query execution, as it did before */
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

    /** @param class-string<Type> $typeClass */
    private function registerType(string $name, string $typeClass): void
    {
        if (false === Type::hasType($name)) {
            Type::addType($name, $typeClass);
        }
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

        $platformMock = Mockery::mock(AbstractPlatform::class);
        $platformMock->shouldReceive('getDateFormatString')
            ->andReturn('Y-m-d');

        /** @info AbstractUidType detects a native GUID type by comparing these two declarations — identical values mean no native GUID type, so uid values convert to their binary representation, as on MySQL */
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
