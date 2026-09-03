<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Test\Functional;

use DateInterval;
use DateTime;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PrecisionSoft\Doctrine\Utility\Exception\Exception;
use PrecisionSoft\Doctrine\Utility\Repository\Criteria\Criteria;
use PrecisionSoft\Doctrine\Utility\Repository\Criteria\Direction;
use PrecisionSoft\Doctrine\Utility\Repository\Criteria\Filter;
use PrecisionSoft\Doctrine\Utility\Repository\Criteria\Keyset;
use PrecisionSoft\Doctrine\Utility\Repository\Criteria\Operator;
use PrecisionSoft\Doctrine\Utility\Repository\Criteria\Sort;
use PrecisionSoft\Doctrine\Utility\Repository\EmptyArrayFilterBehavior;
use PrecisionSoft\Doctrine\Utility\Test\Repository\Fixture\IntBackedEnum;
use PrecisionSoft\Doctrine\Utility\Test\Repository\Fixture\StringBackedEnum;
use PrecisionSoft\Doctrine\Utility\Test\Utility\Entity\FilterSubject;
use PrecisionSoft\Doctrine\Utility\Test\Utility\FilterSubjectRepository;
use PrecisionSoft\Doctrine\Utility\Test\Utility\IntegrationDatabase;
use PrecisionSoft\Doctrine\Utility\Test\Utility\IntegrationManagerRegistry;
use PrecisionSoft\Doctrine\Utility\Test\Utility\SkipIntegrationException;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Uid\Uuid;

/**
 * Assertions are on the rows matched, never on the DQL: a wrongly bound `IN (...)` matches nothing, silently.
 *
 * @internal
 */
#[Group('integration')]
final class ArrayFilterFunctionalTest extends TestCase
{
    private const BINARY_UUID_ALPHA = '11111111-1111-4111-8111-111111111111';
    private const BINARY_UUID_BETA = '22222222-2222-4222-8222-222222222222';
    private const BINARY_UUID_GAMMA = '33333333-3333-4333-8333-333333333333';

    private const UUID_ALPHA = 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa';
    private const UUID_BETA = 'bbbbbbbb-2222-4222-8222-bbbbbbbbbbbb';
    private const UUID_GAMMA = 'cccccccc-3333-4333-8333-cccccccccccc';

    private const ULID_ALPHA = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
    private const ULID_BETA = '01BX5ZZKBKACTAV9WEVGEMMVRZ';
    private const ULID_GAMMA = '01C0N5ZQ8Z9V6M4S3T2R1P0KJH';

    /* first bytes 0x01..0x03: below every hexadecimal character, so a boundary bound as text instead of binary would compare above all three */
    private const IDENTITY_ALPHA = '01111111-1111-4111-8111-111111111111';
    private const IDENTITY_BETA = '02222222-2222-4222-8222-222222222222';
    private const IDENTITY_GAMMA = '03333333-3333-4333-8333-333333333333';

    private ?EntityManagerInterface $entityManager = null;

    /** @var array<string, int> */
    private array $identifiers = [];

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testBinaryUuidArrayFilterMatchesRows(string $environmentVariable): void
    {
        $repository = $this->boot($environmentVariable);

        static::assertSame(
            [$this->identifiers['alpha'], $this->identifiers['gamma']],
            $repository->findIdsByFilters(['binaryUuid' => [static::BINARY_UUID_ALPHA, static::BINARY_UUID_GAMMA]]),
        );
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testUuidArrayFilterMatchesRowsForObjectsAndStrings(string $environmentVariable): void
    {
        $repository = $this->boot($environmentVariable);

        static::assertSame(
            [$this->identifiers['alpha'], $this->identifiers['beta']],
            $repository->findIdsByFilters(['uuid' => [Uuid::fromString(static::UUID_ALPHA), Uuid::fromString(static::UUID_BETA)]]),
            'a uuid column is BINARY(16) on MySQL, so an AbstractUid bound untyped reaches the driver as its 36-character string and matches nothing',
        );

        static::assertSame(
            [$this->identifiers['gamma']],
            $repository->findIdsByFilters(['uuid' => [static::UUID_GAMMA]]),
            'the uid type accepts RFC 4122 strings as well as objects, so both spellings must match the same row',
        );
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testUlidArrayFilterMatchesRowsForObjectsAndStrings(string $environmentVariable): void
    {
        $repository = $this->boot($environmentVariable);

        static::assertSame(
            [$this->identifiers['beta'], $this->identifiers['gamma']],
            $repository->findIdsByFilters(['ulid' => [new Ulid(static::ULID_BETA), static::ULID_GAMMA]]),
        );
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testDateArrayFilterMatchesRowsForObjectsAndStrings(string $environmentVariable): void
    {
        $repository = $this->boot($environmentVariable);

        static::assertSame(
            [$this->identifiers['alpha'], $this->identifiers['beta']],
            $repository->findIdsByFilters(['dateValue' => [new DateTime('2026-01-01'), '2026-02-02']]),
            'a date column must accept both a DateTime and the raw column representation in the same array',
        );
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testDateTimeArrayFilterMatchesRowsWhenMutabilityIsFlipped(string $environmentVariable): void
    {
        $repository = $this->boot($environmentVariable);

        static::assertSame(
            [$this->identifiers['alpha']],
            $repository->findIdsByFilters(['dateTimeValue' => [new DateTimeImmutable('2026-01-01 10:00:00')]]),
            'the `datetime` type rejects a DateTimeImmutable outright; the mutability flip is what makes this match',
        );

        static::assertSame(
            [$this->identifiers['beta']],
            $repository->findIdsByFilters(['dateTimeImmutableValue' => [new DateTime('2026-02-02 11:00:00')]]),
            'and the same in the other direction, on an immutable column',
        );
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testDateImmutableTimeAndTimezoneArrayFiltersMatchRows(string $environmentVariable): void
    {
        $repository = $this->boot($environmentVariable);

        static::assertSame(
            [$this->identifiers['gamma']],
            $repository->findIdsByFilters(['dateImmutableValue' => [new DateTimeImmutable('2026-03-03')]]),
        );

        static::assertSame(
            [$this->identifiers['alpha']],
            $repository->findIdsByFilters(['timeValue' => [new DateTime('1970-01-01 10:00:00')]]),
        );

        static::assertSame(
            [$this->identifiers['beta']],
            $repository->findIdsByFilters(['dateTimeTzValue' => [new DateTime('2026-02-02 11:00:00')]]),
        );
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testDateIntervalArrayFilterMatchesRows(string $environmentVariable): void
    {
        $repository = $this->boot($environmentVariable);

        static::assertSame(
            [$this->identifiers['alpha'], $this->identifiers['gamma']],
            $repository->findIdsByFilters(['intervalValue' => [new DateInterval('P1D'), new DateInterval('P3D')]]),
        );
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testBackedEnumArrayFiltersMatchRows(string $environmentVariable): void
    {
        $repository = $this->boot($environmentVariable);

        static::assertSame(
            [$this->identifiers['alpha'], $this->identifiers['beta']],
            $repository->findIdsByFilters(['intBackedEnum' => [IntBackedEnum::First, IntBackedEnum::Second]]),
            'backed enums keep the untyped binding — Doctrine unwraps them to their scalar backing itself',
        );

        static::assertSame(
            [$this->identifiers['beta']],
            $repository->findIdsByFilters(['stringBackedEnum' => [StringBackedEnum::Beta]]),
        );
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testScalarArrayFilterMatchesRows(string $environmentVariable): void
    {
        $repository = $this->boot($environmentVariable);

        static::assertSame(
            [$this->identifiers['alpha'], $this->identifiers['gamma']],
            $repository->findIdsByFilters(['label' => ['alpha', 'gamma']]),
        );
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testNullFilterMatchesTheRowWithoutAValue(string $environmentVariable): void
    {
        $repository = $this->boot($environmentVariable);

        static::assertSame(
            [$this->identifiers['gamma']],
            $repository->findIdsByFilters(['intBackedEnum' => null]),
        );
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testEmptyArrayFilterMatchesNoRowsAndStillExecutes(string $environmentVariable): void
    {
        $repository = $this->boot($environmentVariable);

        static::assertSame(
            [],
            $repository->findIdsByFilters(['label' => []]),
            'MatchNone emits an always-false literal comparison; only a real server can say the SQL it emits parses',
        );
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testEmptyArrayFilterThrowsWhenTheFlagSaysSo(string $environmentVariable): void
    {
        $repository = $this->boot($environmentVariable);
        $repository->setFlagOverrides([EmptyArrayFilterBehavior::class => EmptyArrayFilterBehavior::ThrowException]);

        $this->expectException(Exception::class);

        $repository->findIdsByFilters(['label' => []]);
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderPortableEngine')]
    public function testTypedCriteriaSupportsFiltersSortLimitAndKeyset(string $environmentVariable): void
    {
        $repository = $this->boot($environmentVariable);
        $sorts = [new Sort('label', Direction::Ascending), new Sort('id', Direction::Ascending)];

        static::assertSame(
            [$this->identifiers['alpha'], $this->identifiers['beta']],
            $repository->findIdsByCriteria(new Criteria(
                filters: [new Filter('label', Operator::NotEqual, 'gamma')],
                sorts: $sorts,
                limit: 2,
            )),
        );
        static::assertSame(
            [$this->identifiers['beta'], $this->identifiers['gamma']],
            $repository->findIdsByCriteria(new Criteria(
                sorts: $sorts,
                keyset: new Keyset(['label' => 'alpha', 'id' => $this->identifiers['alpha']]),
            )),
        );
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderPortableEngine')]
    public function testTypedCriteriaConvertsUidValuesInsideAnInFilter(string $environmentVariable): void
    {
        $repository = $this->boot($environmentVariable);

        static::assertSame(
            [$this->identifiers['alpha'], $this->identifiers['beta']],
            $repository->findIdsByCriteria(new Criteria(
                filters: [new Filter(
                    'uuid',
                    Operator::In,
                    [Uuid::fromString(static::UUID_ALPHA), Uuid::fromString(static::UUID_BETA)],
                )],
                sorts: [new Sort('id', Direction::Ascending)],
            )),
        );
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderPortableEngine')]
    public function testTypedCriteriaConvertsBinaryValuesInsideAnInFilter(string $environmentVariable): void
    {
        $repository = $this->boot($environmentVariable);

        static::assertSame(
            [$this->identifiers['alpha'], $this->identifiers['gamma']],
            $repository->findIdsByCriteria(new Criteria(
                filters: [new Filter(
                    'binaryUuid',
                    Operator::In,
                    [static::BINARY_UUID_ALPHA, static::BINARY_UUID_GAMMA],
                )],
                sorts: [new Sort('id', Direction::Ascending)],
            )),
        );
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderPortableEngine')]
    public function testTypedCriteriaConvertsDateValuesInsideAnInFilter(string $environmentVariable): void
    {
        $repository = $this->boot($environmentVariable);

        static::assertSame(
            [$this->identifiers['alpha'], $this->identifiers['beta']],
            $repository->findIdsByCriteria(new Criteria(
                filters: [new Filter(
                    'dateValue',
                    Operator::In,
                    [new DateTime('2026-01-01'), new DateTimeImmutable('2026-02-02')],
                )],
                sorts: [new Sort('id', Direction::Ascending)],
            )),
        );
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderPortableEngine')]
    public function testTypedCriteriaWalksADescendingKeyset(string $environmentVariable): void
    {
        $repository = $this->boot($environmentVariable);
        $sorts = [new Sort('label', Direction::Descending), new Sort('id', Direction::Descending)];

        static::assertSame(
            [$this->identifiers['gamma'], $this->identifiers['beta']],
            $repository->findIdsByCriteria(new Criteria(sorts: $sorts, limit: 2)),
        );
        static::assertSame(
            [$this->identifiers['beta'], $this->identifiers['alpha']],
            $repository->findIdsByCriteria(new Criteria(
                sorts: $sorts,
                keyset: new Keyset(['label' => 'gamma', 'id' => $this->identifiers['gamma']]),
            )),
        );
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderPortableEngine')]
    public function testTypedCriteriaWalksAKeysetWithMixedDirections(string $environmentVariable): void
    {
        $repository = $this->boot($environmentVariable);
        $sorts = [new Sort('label', Direction::Ascending), new Sort('id', Direction::Descending)];

        static::assertSame(
            [$this->identifiers['beta'], $this->identifiers['gamma']],
            $repository->findIdsByCriteria(new Criteria(
                sorts: $sorts,
                keyset: new Keyset(['label' => 'alpha', 'id' => $this->identifiers['alpha']]),
            )),
        );
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderPortableEngine')]
    public function testTypedCriteriaRejectsANullKeysetValue(string $environmentVariable): void
    {
        $repository = $this->boot($environmentVariable);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('keyset value for `label` must not be null');

        $repository->findIdsByCriteria(new Criteria(
            sorts: [new Sort('label')],
            keyset: new Keyset(['label' => null]),
        ));
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderPortableEngine')]
    public function testTypedCriteriaRejectsANullScalarValue(string $environmentVariable): void
    {
        $repository = $this->boot($environmentVariable);

        /* `gamma` carries no enum: the array API finds it through `IS NULL`, and `= NULL` would find nothing at all */
        static::assertSame(
            [$this->identifiers['gamma']],
            $repository->findIdsByFilters(['stringBackedEnum' => null]),
        );

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('criteria operator `=` does not accept null; use `IS NULL` or `IS NOT NULL`');

        $repository->findIdsByCriteria(new Criteria(
            filters: [new Filter('stringBackedEnum', Operator::Equal, null)],
        ));
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderPortableEngine')]
    public function testTypedCriteriaRejectsANullInsideAList(string $environmentVariable): void
    {
        $repository = $this->boot($environmentVariable);

        /* `NOT IN (..., NULL)` is unknown for every row, so the list would exclude everything without a word */
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('criteria operator `NOT IN` does not accept null inside its list; use `IS NULL` or `IS NOT NULL`');

        $repository->findIdsByCriteria(new Criteria(
            filters: [new Filter('stringBackedEnum', Operator::NotIn, [StringBackedEnum::Alpha, null])],
            sorts: [new Sort('id', Direction::Ascending)],
        ));
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderPortableEngine')]
    public function testTypedCriteriaWalksAKeysetToExhaustion(string $environmentVariable): void
    {
        $repository = $this->boot($environmentVariable);
        $sorts = [new Sort('label', Direction::Ascending), new Sort('id', Direction::Ascending)];
        $keysetValues = [
            $this->identifiers['alpha'] => 'alpha',
            $this->identifiers['beta'] => 'beta',
            $this->identifiers['gamma'] => 'gamma',
        ];

        static::assertSame(
            [[$this->identifiers['alpha']], [$this->identifiers['beta']], [$this->identifiers['gamma']], []],
            $this->walk($repository, $sorts, 'label', $keysetValues),
        );
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderPortableEngine')]
    public function testTypedCriteriaKeysetRejectsANullableSortField(string $environmentVariable): void
    {
        $repository = $this->boot($environmentVariable);
        $sorts = [new Sort('intBackedEnum', Direction::Ascending), new Sort('id', Direction::Ascending)];
        $keysetValues = [
            $this->identifiers['alpha'] => IntBackedEnum::First,
            $this->identifiers['beta'] => IntBackedEnum::Second,
            $this->identifiers['gamma'] => null,
        ];

        /* measured before the guard: postgresql (null last) returned two of the three rows, mysql and mariadb (null first) threw on the null keyset value of the next page */
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('keyset sort field `intBackedEnum` is nullable; a row holding null is never reached by a keyset comparison');

        $this->walk($repository, $sorts, 'intBackedEnum', $keysetValues);
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderPortableEngine')]
    public function testTypedCriteriaConvertsAScalarValueByTheColumnType(string $environmentVariable): void
    {
        $repository = $this->boot($environmentVariable);

        static::assertSame(
            [$this->identifiers['alpha']],
            $repository->findIdsByCriteria(new Criteria(
                filters: [new Filter('uuid', Operator::Equal, Uuid::fromString(static::UUID_ALPHA))],
            )),
        );
        static::assertSame(
            [$this->identifiers['beta']],
            $repository->findIdsByCriteria(new Criteria(
                filters: [new Filter('ulid', Operator::Equal, new Ulid(static::ULID_BETA))],
            )),
        );
        static::assertSame(
            [$this->identifiers['gamma']],
            $repository->findIdsByCriteria(new Criteria(
                filters: [new Filter('binaryUuid', Operator::Equal, static::BINARY_UUID_GAMMA)],
            )),
        );
        static::assertSame(
            [$this->identifiers['beta'], $this->identifiers['gamma']],
            $repository->findIdsByCriteria(new Criteria(
                filters: [new Filter('identity', Operator::GreaterThan, Uuid::fromString(static::IDENTITY_ALPHA))],
                sorts: [new Sort('id', Direction::Ascending)],
            )),
        );
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderPortableEngine')]
    public function testArrayFilterConvertsAScalarValueByTheColumnType(string $environmentVariable): void
    {
        $repository = $this->boot($environmentVariable);

        static::assertSame([$this->identifiers['alpha']], $repository->findIdsByFilters(['uuid' => Uuid::fromString(static::UUID_ALPHA)]));
        static::assertSame([$this->identifiers['beta']], $repository->findIdsByFilters(['ulid' => new Ulid(static::ULID_BETA)]));
        static::assertSame([$this->identifiers['gamma']], $repository->findIdsByFilters(['binaryUuid' => static::BINARY_UUID_GAMMA]));
        static::assertSame([$this->identifiers['alpha']], $repository->findIdsByFilters(['dateValue' => new DateTimeImmutable('2026-01-01')]));
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderPortableEngine')]
    public function testTypedCriteriaConvertsAKeysetValueByTheColumnType(string $environmentVariable): void
    {
        $repository = $this->boot($environmentVariable);

        static::assertSame(
            [$this->identifiers['beta'], $this->identifiers['gamma']],
            $repository->findIdsByCriteria(new Criteria(
                sorts: [new Sort('identity', Direction::Ascending)],
                keyset: new Keyset(['identity' => Uuid::fromString(static::IDENTITY_ALPHA)]),
            )),
        );
    }

    protected function tearDown(): void
    {
        if (null !== $this->entityManager) {
            IntegrationDatabase::dropSchema($this->entityManager);
            $this->entityManager->getConnection()->close();
            $this->entityManager = null;
        }

        $this->identifiers = [];

        parent::tearDown();
    }

    /**
     * Pages of one row until an empty page, the keyset of every page taken from the row it returned.
     *
     * @param list<Sort> $sorts
     * @param array<int, mixed> $keysetValues the sort value of every row, by identifier
     *
     * @return list<list<int>>
     */
    private function walk(FilterSubjectRepository $repository, array $sorts, string $sortField, array $keysetValues): array
    {
        $pages = [];
        $keyset = null;

        do {
            $page = $repository->findIdsByCriteria(new Criteria(sorts: $sorts, keyset: $keyset, limit: 1));
            $pages[] = $page;

            if ([] !== $page) {
                $keyset = new Keyset([$sortField => $keysetValues[$page[0]], 'id' => $page[0]]);
            }
        } while ([] !== $page && \count($pages) < 5);

        return $pages;
    }

    private function boot(string $environmentVariable): FilterSubjectRepository
    {
        IntegrationDatabase::registerTypes();

        try {
            $connection = IntegrationDatabase::createConnection($environmentVariable);
        } catch (SkipIntegrationException $skipIntegrationException) {
            static::markTestSkipped($skipIntegrationException->getMessage());
        }

        $entityManager = IntegrationDatabase::createEntityManager($connection);
        $this->entityManager = $entityManager;

        IntegrationDatabase::createSchema($entityManager);
        $this->seed($entityManager);

        return (new FilterSubjectRepository())
            ->setManagerRegistry(new IntegrationManagerRegistry($entityManager));
    }

    private function seed(EntityManagerInterface $entityManager): void
    {
        $alpha = (new FilterSubject())
            ->setLabel('alpha')
            ->setIdentity(Uuid::fromString(static::IDENTITY_ALPHA))
            ->setBinaryUuid(static::BINARY_UUID_ALPHA)
            ->setUuid(Uuid::fromString(static::UUID_ALPHA))
            ->setUlid(new Ulid(static::ULID_ALPHA))
            ->setDateValue(new DateTime('2026-01-01'))
            ->setDateTimeValue(new DateTime('2026-01-01 10:00:00'))
            ->setDateTimeTzValue(new DateTime('2026-01-01 10:00:00'))
            ->setTimeValue(new DateTime('1970-01-01 10:00:00'))
            ->setDateImmutableValue(new DateTimeImmutable('2026-01-01'))
            ->setDateTimeImmutableValue(new DateTimeImmutable('2026-01-01 10:00:00'))
            ->setIntervalValue(new DateInterval('P1D'))
            ->setIntBackedEnum(IntBackedEnum::First)
            ->setStringBackedEnum(StringBackedEnum::Alpha)
            ->setPayload(['tags' => ['red', 'green'], 'nested' => ['level' => 1]]);

        $beta = (new FilterSubject())
            ->setLabel('beta')
            ->setIdentity(Uuid::fromString(static::IDENTITY_BETA))
            ->setBinaryUuid(static::BINARY_UUID_BETA)
            ->setUuid(Uuid::fromString(static::UUID_BETA))
            ->setUlid(new Ulid(static::ULID_BETA))
            ->setDateValue(new DateTime('2026-02-02'))
            ->setDateTimeValue(new DateTime('2026-02-02 11:00:00'))
            ->setDateTimeTzValue(new DateTime('2026-02-02 11:00:00'))
            ->setTimeValue(new DateTime('1970-01-01 11:00:00'))
            ->setDateImmutableValue(new DateTimeImmutable('2026-02-02'))
            ->setDateTimeImmutableValue(new DateTimeImmutable('2026-02-02 11:00:00'))
            ->setIntervalValue(new DateInterval('P2D'))
            ->setIntBackedEnum(IntBackedEnum::Second)
            ->setStringBackedEnum(StringBackedEnum::Beta)
            ->setPayload(['tags' => ['blue'], 'nested' => ['level' => 2]]);

        $gamma = (new FilterSubject())
            ->setLabel('gamma')
            ->setIdentity(Uuid::fromString(static::IDENTITY_GAMMA))
            ->setBinaryUuid(static::BINARY_UUID_GAMMA)
            ->setUuid(Uuid::fromString(static::UUID_GAMMA))
            ->setUlid(new Ulid(static::ULID_GAMMA))
            ->setDateValue(new DateTime('2026-03-03'))
            ->setDateTimeValue(new DateTime('2026-03-03 12:00:00'))
            ->setDateTimeTzValue(new DateTime('2026-03-03 12:00:00'))
            ->setTimeValue(new DateTime('1970-01-01 12:00:00'))
            ->setDateImmutableValue(new DateTimeImmutable('2026-03-03'))
            ->setDateTimeImmutableValue(new DateTimeImmutable('2026-03-03 12:00:00'))
            ->setIntervalValue(new DateInterval('P3D'))
            ->setPayload(['tags' => ['red'], 'other' => 'value']);

        foreach ([$alpha, $beta, $gamma] as $filterSubject) {
            $entityManager->persist($filterSubject);
        }

        $entityManager->flush();

        $this->identifiers = [
            'alpha' => (int)$alpha->getId(),
            'beta' => (int)$beta->getId(),
            'gamma' => (int)$gamma->getId(),
        ];

        $entityManager->clear();
    }
}
