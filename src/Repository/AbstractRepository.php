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
use PrecisionSoft\Doctrine\Utility\Exception\Exception;
use PrecisionSoft\Doctrine\Utility\Join\JoinCollection;
use ReflectionClass;

abstract class AbstractRepository
{
    public const JOIN_LEFT = Join::LEFT_JOIN;
    public const JOIN_INNER = Join::INNER_JOIN;

    private ManagerRegistry $managerRegistry;

    abstract protected function getEntityClass(): string;

    public static function getAlias(): string
    {
        return \lcfirst((new ReflectionClass(static::class))->getShortName());
    }

    final public function setManagerRegistry(ManagerRegistry $managerRegistry): self
    {
        $this->managerRegistry = $managerRegistry;

        return $this;
    }

    final public function attachFilters(
        QueryBuilder $queryBuilder,
        array $filters,
        string $managerName = null,
    ): ?JoinCollection {
        [$genericFilters, $customFilters] = $this->sortFilters($filters, $managerName);

        if (\count($genericFilters) > 0) {
            $this->attachGenericFilters($queryBuilder, $genericFilters);
        }

        $joinCollection = null;
        if (\count($customFilters) > 0) {
            $joinCollection = $this->attachCustomFilters($queryBuilder, $customFilters);
        }

        if (true === isset($joinCollection) && \count($joinCollection->getJoins()) > 0) {
            $this->attachJoins($queryBuilder, $joinCollection);
        }

        return $joinCollection;
    }

    final protected function execute(
        string $query,
        array $parameters = [],
        string $connectionName = null,
    ): Result {
        $stmt = $this->getConnection($connectionName)->prepare($query);

        return $stmt->executeQuery($parameters);
    }

    final protected function getConnection(
        string $connectionName = null,
    ): Connection {
        return $this->managerRegistry->getConnection($connectionName);
    }

    final protected function createQueryBuilder(
        string $managerName = null,
    ): QueryBuilder {
        return $this->getDoctrineRepository($managerName)->createQueryBuilder(static::getAlias());
    }

    final protected function createQueryBuilderFromFilters(
        array $filters,
        bool $selectJoins = false,
        string $managerName = null,
    ): QueryBuilder {
        $queryBuilder = $this->createQueryBuilder($managerName);

        $joinCollection = $this->attachFilters($queryBuilder, $filters, $managerName);

        if (true === $selectJoins && null !== $joinCollection) {
            $queryBuilder->addSelect($joinCollection->getAliases());
        }

        return $queryBuilder;
    }

    final protected function sortFilters(
        array $filters,
        string $managerName = null,
    ): array {
        $genericFilters = $customFilters = [];

        $doctrineRepository = $this->getDoctrineRepository($managerName);

        foreach ($filters as $filter => $value) {
            if (true === $doctrineRepository->hasField($filter)) {
                $genericFilters[$filter] = $value;
                continue;
            }

            $customFilters[$filter] = $value;
        }

        return [$genericFilters, $customFilters];
    }

    final protected function attachJoins(
        QueryBuilder $queryBuilder,
        JoinCollection $joinCollection,
    ): void {
        foreach ($joinCollection->getJoins() as $join) {
            switch ($join->getJoinType()) {
                case static::JOIN_INNER:
                    $queryBuilder->innerJoin(
                        $join->getJoin(),
                        $join->getAlias(),
                        $join->getConditionType(),
                        $join->getCondition(),
                        $join->getIndexBy(),
                    );
                    break;
                case static::JOIN_LEFT:
                    $queryBuilder->leftJoin(
                        $join->getJoin(),
                        $join->getAlias(),
                        $join->getConditionType(),
                        $join->getCondition(),
                        $join->getIndexBy(),
                    );
                    break;
                default:
                    throw new Exception(\sprintf('invalid join type `%s`', $join->getJoinType()));
            }
        }
    }

    final protected function getDoctrineRepository(
        string $managerName = null,
    ): DoctrineRepository {
        $managerName ??= $this->getManagerName();

        $repository = $this->managerRegistry->getRepository($this->getEntityClass(), $managerName);

        if (($repository instanceof DoctrineRepository) === false) {
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
        /* overwrite if the entity has a different manager than the default */
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
        foreach ($filters as $key => $value) {
            $condition = \is_array($value) ? "IN (:{$key})" : "= :{$key}";

            $queryBuilder->andWhere(static::getAlias() . ".{$key} {$condition}")
                ->setParameter($key, $value);
        }
    }
}
