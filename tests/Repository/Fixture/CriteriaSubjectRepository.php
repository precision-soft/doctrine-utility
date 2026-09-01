<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Test\Repository\Fixture;

use Doctrine\ORM\QueryBuilder;
use PrecisionSoft\Doctrine\Utility\Repository\AbstractRepository;
use PrecisionSoft\Doctrine\Utility\Repository\Criteria\Criteria;
use PrecisionSoft\Doctrine\Utility\Repository\DoctrineRepository;
use stdClass;

/**
 * Names the alias, so a criteria test can assert the generated DQL verbatim.
 *
 * @internal
 */
class CriteriaSubjectRepository extends AbstractRepository
{
    /** @var list<string> */
    public array $mappedFields = [];

    public function __construct(
        private readonly QueryBuilder $queryBuilder,
        private readonly DoctrineRepository $doctrineRepository,
    ) {}

    public function build(Criteria $criteria): QueryBuilder
    {
        return $this->createQueryBuilderFromCriteria($criteria);
    }

    protected function getEntityClass(): string
    {
        return stdClass::class;
    }

    protected function createQueryBuilder(?string $managerName = null): QueryBuilder
    {
        return $this->queryBuilder;
    }

    protected function getDoctrineRepository(?string $managerName = null): DoctrineRepository
    {
        return $this->doctrineRepository;
    }

    /** @param non-empty-array<array-key, mixed> $filterValue */
    protected function convertArrayFilterValues(string $filterName, array $filterValue): array
    {
        return [$filterValue, null];
    }
}
