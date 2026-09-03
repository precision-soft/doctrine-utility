<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Test\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr;
use Doctrine\ORM\QueryBuilder;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use PrecisionSoft\Doctrine\Utility\Exception\Exception;
use PrecisionSoft\Doctrine\Utility\Repository\Criteria\Criteria;
use PrecisionSoft\Doctrine\Utility\Repository\Criteria\Direction;
use PrecisionSoft\Doctrine\Utility\Repository\Criteria\Filter;
use PrecisionSoft\Doctrine\Utility\Repository\Criteria\Keyset;
use PrecisionSoft\Doctrine\Utility\Repository\Criteria\Operator;
use PrecisionSoft\Doctrine\Utility\Repository\Criteria\Sort;
use PrecisionSoft\Doctrine\Utility\Repository\DoctrineRepository;
use PrecisionSoft\Doctrine\Utility\Test\Repository\Fixture\CriteriaSubjectRepository;
use stdClass;

/**
 * Asserts the DQL itself: the row counts are covered by the functional suite, the clause shape is not.
 *
 * @internal
 */
final class CriteriaQueryBuilderTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const DQL_PREFIX = 'SELECT FROM stdClass criteriaSubjectRepository ';

    public function testEachComparisonOperatorReachesTheDqlWithItsOwnParameter(): void
    {
        $queryBuilder = $this->build(new Criteria(filters: [
            new Filter('label', Operator::Equal, 'alpha'),
            new Filter('position', Operator::GreaterThanOrEqual, 5),
            new Filter('code', Operator::Like, '%beta%'),
        ]));

        static::assertSame(
            static::DQL_PREFIX . 'WHERE criteriaSubjectRepository.label = :criteria_0'
            . ' AND criteriaSubjectRepository.position >= :criteria_1'
            . ' AND criteriaSubjectRepository.code LIKE :criteria_2',
            $queryBuilder->getDQL(),
        );
        static::assertSame('alpha', $queryBuilder->getParameter('criteria_0')?->getValue());
        static::assertSame(5, $queryBuilder->getParameter('criteria_1')?->getValue());
    }

    public function testTheSameFieldCanCarryTwoFiltersWithoutTheParametersColliding(): void
    {
        $queryBuilder = $this->build(new Criteria(filters: [
            new Filter('position', Operator::GreaterThan, 1),
            new Filter('position', Operator::LessThan, 9),
        ]));

        static::assertSame(
            static::DQL_PREFIX . 'WHERE criteriaSubjectRepository.position > :criteria_0 AND criteriaSubjectRepository.position < :criteria_1',
            $queryBuilder->getDQL(),
        );
        static::assertSame(1, $queryBuilder->getParameter('criteria_0')?->getValue());
        static::assertSame(9, $queryBuilder->getParameter('criteria_1')?->getValue());
    }

    public function testTheNullOperatorsBindNoParameter(): void
    {
        $queryBuilder = $this->build(new Criteria(filters: [
            new Filter('deletedAt', Operator::IsNull),
            new Filter('label', Operator::IsNotNull),
        ]));

        static::assertSame(
            static::DQL_PREFIX . 'WHERE criteriaSubjectRepository.deletedAt IS NULL AND criteriaSubjectRepository.label IS NOT NULL',
            $queryBuilder->getDQL(),
        );
        static::assertCount(0, $queryBuilder->getParameters());
    }

    public function testTheListOperatorsWrapTheirParameterInParentheses(): void
    {
        $queryBuilder = $this->build(new Criteria(filters: [
            new Filter('label', Operator::In, ['alpha', 'beta']),
            new Filter('code', Operator::NotIn, ['gamma']),
        ]));

        static::assertSame(
            static::DQL_PREFIX . 'WHERE criteriaSubjectRepository.label IN (:criteria_0) AND criteriaSubjectRepository.code NOT IN (:criteria_1)',
            $queryBuilder->getDQL(),
        );
    }

    public function testSortsKeepTheirOrderAndDirection(): void
    {
        $queryBuilder = $this->build(new Criteria(sorts: [
            new Sort('label', Direction::Descending),
            new Sort('id', Direction::Ascending),
        ]));

        static::assertSame(
            static::DQL_PREFIX . 'ORDER BY criteriaSubjectRepository.label DESC, criteriaSubjectRepository.id ASC',
            $queryBuilder->getDQL(),
        );
    }

    public function testASingleSortKeysetComparesOnlyThatField(): void
    {
        $queryBuilder = $this->build(new Criteria(
            sorts: [new Sort('id')],
            keyset: new Keyset(['id' => 7]),
        ));

        static::assertSame(
            static::DQL_PREFIX . 'WHERE (criteriaSubjectRepository.id > :keyset_0) ORDER BY criteriaSubjectRepository.id ASC',
            $queryBuilder->getDQL(),
        );
        static::assertSame(7, $queryBuilder->getParameter('keyset_0')?->getValue());
    }

    public function testAMixedDirectionKeysetEmulatesARowValueComparison(): void
    {
        $queryBuilder = $this->build(new Criteria(
            sorts: [new Sort('label', Direction::Ascending), new Sort('id', Direction::Descending)],
            keyset: new Keyset(['label' => 'alpha', 'id' => 7]),
        ));

        static::assertSame(
            static::DQL_PREFIX . 'WHERE (criteriaSubjectRepository.label > :keyset_0'
            . ' OR (criteriaSubjectRepository.label = :keyset_0 AND criteriaSubjectRepository.id < :keyset_1))'
            . ' ORDER BY criteriaSubjectRepository.label ASC, criteriaSubjectRepository.id DESC',
            $queryBuilder->getDQL(),
        );
    }

    public function testAThreeFieldKeysetChainsEveryPrecedingEquality(): void
    {
        $queryBuilder = $this->build(new Criteria(
            sorts: [new Sort('label'), new Sort('code'), new Sort('id')],
            keyset: new Keyset(['label' => 'alpha', 'code' => 'beta', 'id' => 7]),
        ));

        static::assertStringContainsString(
            '(criteriaSubjectRepository.label = :keyset_0 AND criteriaSubjectRepository.code = :keyset_1'
            . ' AND criteriaSubjectRepository.id > :keyset_2)',
            $queryBuilder->getDQL(),
        );
    }

    public function testTheSmallestAcceptedLimitIsOne(): void
    {
        static::assertSame(1, $this->build(new Criteria(limit: 1))->getMaxResults());
    }

    public function testANullValueWithAComparisonOperatorIsRejected(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('criteria operator `=` does not accept null; use `IS NULL` or `IS NOT NULL`');

        $this->build(new Criteria(filters: [new Filter('deletedAt', Operator::Equal, null)]));
    }

    public function testAFilterWithoutAValueIsRejectedBeforeItBindsNull(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('criteria operator `<>` does not accept null; use `IS NULL` or `IS NOT NULL`');

        $this->build(new Criteria(filters: [new Filter('deletedAt', Operator::NotEqual)]));
    }

    public function testANullInsideAListOperatorIsRejected(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('criteria operator `NOT IN` does not accept null inside its list; use `IS NULL` or `IS NOT NULL`');

        $this->build(new Criteria(filters: [new Filter('id', Operator::NotIn, [1, null])]));
    }

    public function testANullableSortFieldIsAcceptedWithoutAKeyset(): void
    {
        $queryBuilder = $this->build(new Criteria(sorts: [new Sort('deletedAt')]), nullableFields: ['deletedAt']);

        static::assertSame(
            static::DQL_PREFIX . 'ORDER BY criteriaSubjectRepository.deletedAt ASC',
            $queryBuilder->getDQL(),
        );
    }

    public function testANullableSortFieldIsRejectedUnderAKeyset(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('keyset sort field `deletedAt` is nullable; a row holding null is never reached by a keyset comparison');

        $this->build(
            new Criteria(sorts: [new Sort('deletedAt'), new Sort('id')], keyset: new Keyset(['deletedAt' => 1, 'id' => 1])),
            nullableFields: ['deletedAt'],
        );
    }

    public function testAnAssociationTakesEveryOperatorButLike(): void
    {
        $queryBuilder = $this->build(new Criteria(
            filters: [
                new Filter('owner', Operator::Equal, 7),
                new Filter('owner', Operator::GreaterThan, 7),
                new Filter('owner', Operator::In, [7, 8]),
                new Filter('owner', Operator::IsNull),
            ],
            sorts: [new Sort('owner')],
        ), associations: ['owner']);

        static::assertSame(
            static::DQL_PREFIX . 'WHERE criteriaSubjectRepository.owner = :criteria_0'
            . ' AND criteriaSubjectRepository.owner > :criteria_1'
            . ' AND criteriaSubjectRepository.owner IN (:criteria_2)'
            . ' AND criteriaSubjectRepository.owner IS NULL'
            . ' ORDER BY criteriaSubjectRepository.owner ASC',
            $queryBuilder->getDQL(),
        );
    }

    public function testALikeOnAnAssociationIsRejected(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('criteria operator `LIKE` cannot apply to the association `owner`');

        $this->build(new Criteria(filters: [new Filter('owner', Operator::Like, '%x%')]), associations: ['owner']);
    }

    /**
     * @param list<string> $nullableFields
     * @param list<string> $associations
     */
    private function build(Criteria $criteria, array $nullableFields = [], array $associations = []): QueryBuilder
    {
        $entityManagerMock = Mockery::mock(EntityManagerInterface::class);
        $entityManagerMock->shouldReceive('getExpressionBuilder')
            ->andReturn(new Expr());

        $queryBuilder = new QueryBuilder($entityManagerMock);
        $queryBuilder->from(stdClass::class, CriteriaSubjectRepository::getAlias());

        $doctrineRepositoryMock = Mockery::mock(DoctrineRepository::class);
        $doctrineRepositoryMock->shouldReceive('hasField')
            ->andReturnTrue();
        $doctrineRepositoryMock->shouldReceive('allowsNull')
            ->andReturnUsing(static fn(string $fieldName): bool => true === \in_array($fieldName, $nullableFields, true));
        $doctrineRepositoryMock->shouldReceive('hasAssociation')
            ->andReturnUsing(static fn(string $fieldName): bool => true === \in_array($fieldName, $associations, true));

        return (new CriteriaSubjectRepository($queryBuilder, $doctrineRepositoryMock))->build($criteria);
    }
}
