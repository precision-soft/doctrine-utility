<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use PrecisionSoft\Doctrine\Utility\Exception\Exception;
use PrecisionSoft\Doctrine\Utility\Join\JoinCollection;
use ReflectionClass;

abstract class AbstractRepository
{
    public const JOIN_LEFT = Join::LEFT_JOIN;
    public const JOIN_INNER = Join::INNER_JOIN;

    private static array $aliasCache = [];

    private ManagerRegistry $managerRegistry;

    abstract protected function getEntityClass(): string;

    public static function getAlias(): string
    {
        return self::$aliasCache[static::class] ??= \lcfirst((new ReflectionClass(static::class))->getShortName());
    }

    /** @internal used by the dependency injection system */
    final public function setManagerRegistry(ManagerRegistry $managerRegistry): self
    {
        $this->managerRegistry = $managerRegistry;

        return $this;
    }

    final public function refresh(object $entity): void
    {
        $this->getManager()->refresh($entity);
    }

    final protected function attachFilters(
        QueryBuilder $queryBuilder,
        array $filters,
        ?string $managerName = null,
    ): ?JoinCollection {
        [$genericFilters, $customFilters] = $this->sortFilters($filters, $managerName);

        if (0 < \count($genericFilters)) {
            $this->attachGenericFilters($queryBuilder, $genericFilters);
        }

        $joinCollection = null;
        if (0 < \count($customFilters)) {
            $joinCollection = $this->attachCustomFilters($queryBuilder, $customFilters);
        }

        if (null !== $joinCollection && 0 < \count($joinCollection->getJoins())) {
            $this->attachJoins($queryBuilder, $joinCollection);
        }

        return $joinCollection;
    }

    final protected function getManager(): ObjectManager
    {
        return $this->managerRegistry->getManager($this->getManagerName());
    }

    final protected function execute(
        string $query,
        array $parameters = [],
        ?string $connectionName = null,
    ): Result {
        $stmt = $this->getConnection($connectionName)->prepare($query);

        foreach ($parameters as $parameterKey => $parameterValue) {
            $stmt->bindValue($parameterKey, $parameterValue);
        }

        return $stmt->executeQuery();
    }

    final protected function getConnection(
        ?string $connectionName = null,
    ): Connection {
        return $this->managerRegistry->getConnection($connectionName);
    }

    final protected function createQueryBuilder(
        ?string $managerName = null,
    ): QueryBuilder {
        return $this->getDoctrineRepository($managerName)->createQueryBuilder(static::getAlias());
    }

    final protected function createQueryBuilderFromFilters(
        array $filters,
        bool $selectJoins = false,
        ?string $managerName = null,
    ): QueryBuilder {
        $queryBuilder = $this->createQueryBuilder($managerName);

        $joinCollection = $this->attachFilters($queryBuilder, $filters, $managerName);

        if (true === $selectJoins && null !== $joinCollection) {
            $queryBuilder->addSelect($joinCollection->getAliases());
        }

        return $queryBuilder;
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    final protected function sortFilters(
        array $filters,
        ?string $managerName = null,
    ): array {
        $genericFilters = $customFilters = [];

        $doctrineRepository = $this->getDoctrineRepository($managerName);

        foreach ($filters as $filterName => $filterValue) {
            if (true === $doctrineRepository->hasField($filterName)) {
                $genericFilters[$filterName] = $filterValue;
                continue;
            }

            $customFilters[$filterName] = $filterValue;
        }

        return [$genericFilters, $customFilters];
    }

    final protected function attachJoins(
        QueryBuilder $queryBuilder,
        JoinCollection $joinCollection,
    ): void {
        foreach ($joinCollection->getJoins() as $join) {
            match ($join->getJoinType()) {
                static::JOIN_INNER => $queryBuilder->innerJoin(
                    $join->getJoin(),
                    $join->getAlias(),
                    $join->getConditionType(),
                    $join->getCondition(),
                    $join->getIndexBy(),
                ),
                static::JOIN_LEFT => $queryBuilder->leftJoin(
                    $join->getJoin(),
                    $join->getAlias(),
                    $join->getConditionType(),
                    $join->getCondition(),
                    $join->getIndexBy(),
                ),
                default => throw new Exception(\sprintf('invalid join type `%s`', $join->getJoinType())),
            };
        }
    }

    final protected function getDoctrineRepository(
        ?string $managerName = null,
    ): DoctrineRepository {
        $managerName ??= $this->getManagerName();

        $repository = $this->managerRegistry->getRepository($this->getEntityClass(), $managerName);

        if (false === ($repository instanceof DoctrineRepository)) {
            throw new Exception(
                \sprintf(
                    'if you are using `%s` you must use `%s` for the entity repository',
                    self::class,
                    DoctrineRepository::class,
                ),
            );
        }

        return $repository;
    }

    protected function getManagerName(): ?string
    {
        return null;
    }

    protected function attachCustomFilters(
        QueryBuilder $queryBuilder,
        array $filters,
    ): JoinCollection {
        throw new Exception(
            \sprintf('overwrite `%s` in `%s`', __METHOD__, static::class),
        );
    }

    private function attachGenericFilters(
        QueryBuilder $queryBuilder,
        array $filters,
    ): void {
        foreach ($filters as $filterName => $filterValue) {
            if (null === $filterValue) {
                $queryBuilder->andWhere(static::getAlias() . ".{$filterName} IS NULL");
                continue;
            }

            $filterCondition = true === \is_array($filterValue) ? "IN (:{$filterName})" : "= :{$filterName}";

            $queryBuilder->andWhere(static::getAlias() . ".{$filterName} {$filterCondition}")
                ->setParameter($filterName, $filterValue);
        }
    }
}
