<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Test\Utility;

use PrecisionSoft\Doctrine\Utility\Exception\Exception;
use PrecisionSoft\Doctrine\Utility\Repository\AbstractRepository;
use PrecisionSoft\Doctrine\Utility\Repository\Criteria\Criteria;
use PrecisionSoft\Doctrine\Utility\Test\Utility\Entity\FilterSubject;
use UnitEnum;

/**
 * Selects identifiers, not entities: hydration would drag every custom type's read side into these assertions.
 *
 * @internal
 */
final class FilterSubjectRepository extends AbstractRepository
{
    /** @var array<class-string<UnitEnum>, UnitEnum> */
    private array $flagOverrides = [];

    /** @return list<int> */
    public function findIdsByCriteria(Criteria $criteria): array
    {
        $queryBuilder = $this->createQueryBuilderFromCriteria($criteria);
        $queryBuilder->select(static::getAlias() . '.id');

        /** @var list<array{id: int}> $rows */
        $rows = $queryBuilder->getQuery()->getArrayResult();

        return \array_map(static fn(array $row): int => $row['id'], $rows);
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array<int, int>
     * @throws Exception if a filter name is not a mapped field or an empty array filter is rejected
     */
    public function findIdsByFilters(array $filters): array
    {
        $queryBuilder = $this->createQueryBuilderFromFilters($filters);

        $queryBuilder
            ->select(static::getAlias() . '.id')
            ->orderBy(static::getAlias() . '.id', 'ASC');

        /** @var array<int, array{id: int}> $rows */
        $rows = $queryBuilder->getQuery()->getArrayResult();

        return \array_map(static fn(array $row): int => $row['id'], $rows);
    }

    /**
     * @param array<class-string<UnitEnum>, UnitEnum> $flagOverrides
     */
    public function setFlagOverrides(array $flagOverrides): static
    {
        $this->flagOverrides = $flagOverrides;

        return $this;
    }

    /** @return class-string<FilterSubject> */
    protected function getEntityClass(): string
    {
        return FilterSubject::class;
    }

    /** @return array<class-string<UnitEnum>, UnitEnum> */
    protected function getFlags(): array
    {
        return $this->flagOverrides + parent::getFlags();
    }
}
