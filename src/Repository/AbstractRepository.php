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
use Psr\Log\LoggerInterface;
use ReflectionClass;
use UnitEnum;

abstract class AbstractRepository
{
    public const JOIN_LEFT = Join::LEFT_JOIN;
    public const JOIN_INNER = Join::INNER_JOIN;

    /** @var array<class-string, string> */
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

    /** @param array<string, mixed> $filters */
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

    /** @param array<string, mixed> $filters */
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
        ?string $managerName = null,
    ): DoctrineRepository {
        $managerName ??= $this->getManagerName();

        $repository = $this->managerRegistry->getRepository($this->getEntityClass(), $managerName);

        if (false === ($repository instanceof DoctrineRepository)) {
            throw new Exception(
                \sprintf(
                    'if you are using `%s` you must use `%s` for the entity repository',
                    static::class,
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

    /** @param array<string, mixed> $filters */
    protected function attachCustomFilters(
        QueryBuilder $queryBuilder,
        array $filters,
    ): JoinCollection {
        throw new Exception(
            \sprintf('overwrite `%s` in `%s`', __METHOD__, static::class),
        );
    }

    /**
     * Override to customize repository behavior. Each entry maps a flag enum class
     * to one of its cases; absent flags fall back to abstract repository defaults.
     *
     * @return array<class-string<UnitEnum>, UnitEnum>
     */
    protected function getFlags(): array
    {
        return [
            EmptyArrayFilterBehavior::class => EmptyArrayFilterBehavior::MatchNone,
        ];
    }

    protected function getLogger(): ?LoggerInterface
    {
        return null;
    }

    /** @param array<string, mixed> $filters */
    private function attachGenericFilters(
        QueryBuilder $queryBuilder,
        array $filters,
    ): void {
        foreach ($filters as $filterName => $filterValue) {
            if (null === $filterValue) {
                $queryBuilder->andWhere(static::getAlias() . ".{$filterName} IS NULL");
                continue;
            }

            if (true === \is_array($filterValue)) {
                if ([] === $filterValue) {
                    $this->handleEmptyArrayFilter($queryBuilder, $filterName);
                    continue;
                }

                $queryBuilder->andWhere(static::getAlias() . ".{$filterName} IN (:{$filterName})")
                    ->setParameter($filterName, $filterValue);
                continue;
            }

            $queryBuilder->andWhere(static::getAlias() . ".{$filterName} = :{$filterName}")
                ->setParameter($filterName, $filterValue);
        }
    }

    private function handleEmptyArrayFilter(
        QueryBuilder $queryBuilder,
        string $filterName,
    ): void {
        $emptyArrayFilterBehavior = $this->getFlag(
            EmptyArrayFilterBehavior::class,
            EmptyArrayFilterBehavior::MatchNone,
        );

        switch ($emptyArrayFilterBehavior) {
            case EmptyArrayFilterBehavior::ThrowException:
                throw new Exception(
                    \sprintf(
                        'invalid filter `%s` in `%s`: expected non-empty array, got empty array',
                        $filterName,
                        static::class,
                    ),
                );
            case EmptyArrayFilterBehavior::MatchNone:
                $this->getLogger()?->warning(
                    'empty array filter forced to match no rows',
                    [
                        'repository' => static::class,
                        'filter' => $filterName,
                        'hint' => 'pass a non-empty array, omit the filter, or override `getFlags()` with `EmptyArrayFilterBehavior::ThrowException`',
                    ],
                );

                $queryBuilder->andWhere(
                    \sprintf("'%s' = '%s-emptyFilter'", $filterName, $filterName),
                );

                return;
            default:
                throw new Exception(\sprintf('unsupported empty array filter behavior `%s`', $emptyArrayFilterBehavior->name));
        }
    }

    /**
     * @template TFlag of UnitEnum
     *
     * @param class-string<TFlag> $flagClass
     * @param TFlag $default
     *
     * @return TFlag
     */
    private function getFlag(string $flagClass, UnitEnum $default): UnitEnum
    {
        $flag = $this->getFlags()[$flagClass] ?? null;

        if (false === ($flag instanceof $flagClass)) {
            return $default;
        }

        return $flag;
    }
}
