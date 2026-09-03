<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Test\Utility;

use PrecisionSoft\Doctrine\Utility\Exception\Exception;
use PrecisionSoft\Doctrine\Utility\Repository\AbstractRepository;
use PrecisionSoft\Doctrine\Utility\Repository\Criteria\Criteria;
use PrecisionSoft\Doctrine\Utility\Test\Utility\Entity\FilterSubjectTag;

/**
 * The owning side of the one association in the fixtures, so criteria on an association can be exercised.
 *
 * @internal
 */
final class FilterSubjectTagRepository extends AbstractRepository
{
    /**
     * @return list<int>
     * @throws Exception if the criteria names an unmapped field or an operator the field cannot take
     */
    public function findIdsByCriteria(Criteria $criteria): array
    {
        $queryBuilder = $this->createQueryBuilderFromCriteria($criteria);
        $queryBuilder->select(static::getAlias() . '.id');

        /** @var list<array{id: int}> $rows */
        $rows = $queryBuilder->getQuery()->getArrayResult();

        return \array_map(static fn(array $row): int => $row['id'], $rows);
    }

    /** @return class-string<FilterSubjectTag> */
    protected function getEntityClass(): string
    {
        return FilterSubjectTag::class;
    }
}
