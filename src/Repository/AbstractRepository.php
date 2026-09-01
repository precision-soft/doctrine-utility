<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Repository;

use DateInterval;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\DateIntervalType;
use Doctrine\DBAL\Types\PhpDateMappingType;
use Doctrine\DBAL\Types\PhpDateTimeMappingType;
use Doctrine\DBAL\Types\PhpTimeMappingType;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Error;
use PrecisionSoft\Doctrine\Utility\Exception\Exception;
use PrecisionSoft\Doctrine\Utility\Join\JoinCollection;
use PrecisionSoft\Doctrine\Utility\Repository\Criteria\Criteria;
use PrecisionSoft\Doctrine\Utility\Repository\Criteria\Direction;
use PrecisionSoft\Doctrine\Utility\Repository\Criteria\Filter;
use PrecisionSoft\Doctrine\Utility\Repository\Criteria\Operator;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Symfony\Bridge\Doctrine\Types\AbstractUidType;
use UnitEnum;

abstract class AbstractRepository
{
    public const JOIN_LEFT = Join::LEFT_JOIN;
    public const JOIN_INNER = Join::INNER_JOIN;

    /** @var array<class-string, string> */
    protected static array $aliasCache = [];

    protected ManagerRegistry $managerRegistry;

    /** @return class-string<object> */
    abstract protected function getEntityClass(): string;

    public static function getAlias(): string
    {
        return static::$aliasCache[static::class] ??= \lcfirst((new ReflectionClass(static::class))->getShortName());
    }

    /** @internal used by the dependency injection system */
    public function setManagerRegistry(ManagerRegistry $managerRegistry): static
    {
        $this->managerRegistry = $managerRegistry;

        return $this;
    }

    public function refresh(object $entity): void
    {
        $this->getManager()->refresh($entity);
    }

    /**
     * @param array<string, mixed> $filters
     * @throws Exception if custom filters are present and attachCustomFilters() has not been overridden, or if an invalid join type is encountered
     */
    protected function attachFilters(
        QueryBuilder $queryBuilder,
        array $filters,
        ?string $managerName = null,
    ): ?JoinCollection {
        [$genericFilters, $customFilters] = $this->sortFilters($filters, $managerName);

        if (\count($genericFilters) > 0) {
            $this->attachGenericFilters($queryBuilder, $genericFilters);
        }

        $joinCollection = null;
        if (\count($customFilters) > 0) {
            $joinCollection = $this->attachCustomFilters($queryBuilder, $customFilters);
        }

        if (null !== $joinCollection && \count($joinCollection->getJoins()) > 0) {
            $this->attachJoins($queryBuilder, $joinCollection);
        }

        return $joinCollection;
    }

    protected function getManager(): ObjectManager
    {
        return $this->managerRegistry->getManager($this->getManagerName());
    }

    /**
     * @param array<string, mixed> $parameters
     * @throws Exception if the ManagerRegistry returns a connection that is not a Connection instance
     */
    protected function execute(
        string $query,
        array $parameters = [],
        ?string $connectionName = null,
    ): Result {
        $statement = $this->getConnection($connectionName)->prepare($query);

        foreach ($parameters as $parameterKey => $parameterValue) {
            $statement->bindValue($parameterKey, $parameterValue);
        }

        return $statement->executeQuery();
    }

    /**
     * @throws Exception if the ManagerRegistry returns a connection that is not a Connection instance
     */
    protected function getConnection(
        ?string $connectionName = null,
    ): Connection {
        $connection = $this->managerRegistry->getConnection($connectionName);

        if (false === ($connection instanceof Connection)) {
            throw new Exception('connection is not an instance of Connection');
        }

        return $connection;
    }

    /**
     * @throws Exception if the entity repository is not a DoctrineRepository
     */
    protected function createQueryBuilder(
        ?string $managerName = null,
    ): QueryBuilder {
        return $this->getDoctrineRepository($managerName)->createQueryBuilder(static::getAlias());
    }

    /**
     * @param array<string, mixed> $filters
     * @throws Exception if the entity repository is not a DoctrineRepository, or if custom filters are present and attachCustomFilters() has not been overridden
     */
    protected function createQueryBuilderFromFilters(
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
     * @throws Exception if a criteria field is not mapped, or the criteria itself is inconsistent
     */
    protected function createQueryBuilderFromCriteria(
        Criteria $criteria,
        ?string $managerName = null,
    ): QueryBuilder {
        $queryBuilder = $this->createQueryBuilder($managerName);
        $doctrineRepository = $this->getDoctrineRepository($managerName);

        foreach ($criteria->filters as $index => $filter) {
            if (false === $doctrineRepository->hasField($filter->field)) {
                throw new Exception(\sprintf('criteria field `%s` is not mapped', $filter->field));
            }

            $this->attachCriteriaFilter($queryBuilder, $filter, 'criteria_' . $index);
        }

        foreach ($criteria->sorts as $sort) {
            if (false === $doctrineRepository->hasField($sort->field)) {
                throw new Exception(\sprintf('sort field `%s` is not mapped', $sort->field));
            }

            $queryBuilder->addOrderBy(static::getAlias() . '.' . $sort->field, $sort->direction->value);
        }

        if (null !== $criteria->keyset) {
            $this->attachCriteriaKeyset($queryBuilder, $criteria);
        }

        if (null !== $criteria->limit) {
            if ($criteria->limit < 1) {
                throw new Exception('criteria limit must be positive');
            }

            $queryBuilder->setMaxResults($criteria->limit);
        }

        return $queryBuilder;
    }

    /**
     * @throws Exception if the operator needs an array value and did not get one, or the empty array behavior throws
     */
    protected function attachCriteriaFilter(
        QueryBuilder $queryBuilder,
        Filter $filter,
        string $parameterName,
    ): void {
        $field = static::getAlias() . '.' . $filter->field;

        if (Operator::IsNull === $filter->operator || Operator::IsNotNull === $filter->operator) {
            $queryBuilder->andWhere($field . ' ' . $filter->operator->value);

            return;
        }

        if (Operator::In !== $filter->operator && Operator::NotIn !== $filter->operator) {
            if (true === \is_array($filter->value)) {
                throw new Exception(
                    \sprintf('criteria operator `%s` requires a single value', $filter->operator->value),
                );
            }

            $queryBuilder->andWhere(\sprintf('%s %s :%s', $field, $filter->operator->value, $parameterName))
                ->setParameter($parameterName, $filter->value);

            return;
        }

        if (false === \is_array($filter->value)) {
            throw new Exception(
                \sprintf('criteria operator `%s` requires an array value', $filter->operator->value),
            );
        }

        if ([] === $filter->value) {
            $this->handleEmptyCriteriaArrayFilter($queryBuilder, $filter);

            return;
        }

        [$databaseValues, $parameterType] = $this->convertArrayFilterValues($filter->field, $filter->value);

        $queryBuilder->andWhere(\sprintf('%s %s (:%s)', $field, $filter->operator->value, $parameterName))
            ->setParameter($parameterName, $databaseValues, $parameterType);
    }

    /**
     * @throws Exception if the empty array filter behavior is set to ThrowException
     */
    protected function handleEmptyCriteriaArrayFilter(QueryBuilder $queryBuilder, Filter $filter): void
    {
        $emptyArrayFilterBehavior = $this->getFlag(
            EmptyArrayFilterBehavior::class,
            EmptyArrayFilterBehavior::MatchNone,
        );

        /* `NOT IN ()` excludes no row, so outside the throwing behavior it has to add no clause at all */
        if (
            Operator::In === $filter->operator
            || EmptyArrayFilterBehavior::ThrowException === $emptyArrayFilterBehavior
        ) {
            $this->handleEmptyArrayFilter($queryBuilder, $filter->field);
        }
    }

    /**
     * Emulates a row value comparison, which neither DQL nor every supported engine can express directly.
     *
     * @throws Exception if there is nothing to sort by, or a keyset value is missing or null
     */
    protected function attachCriteriaKeyset(QueryBuilder $queryBuilder, Criteria $criteria): void
    {
        if (null === $criteria->keyset) {
            return;
        }

        if ([] === $criteria->sorts) {
            throw new Exception('keyset pagination requires at least one sort');
        }

        $alias = static::getAlias();
        $conditions = [];

        foreach ($criteria->sorts as $index => $sort) {
            if (false === \array_key_exists($sort->field, $criteria->keyset->values)) {
                throw new Exception(\sprintf('keyset value for `%s` is missing', $sort->field));
            }

            if (null === $criteria->keyset->values[$sort->field]) {
                throw new Exception(\sprintf('keyset value for `%s` must not be null', $sort->field));
            }

            $equalities = [];

            for ($previous = 0; $previous < $index; ++$previous) {
                $equalities[] = \sprintf('%s.%s = :keyset_%s', $alias, $criteria->sorts[$previous]->field, $previous);
            }

            $comparison = Direction::Ascending === $sort->direction ? '>' : '<';
            $tail = \sprintf('%s.%s %s :keyset_%s', $alias, $sort->field, $comparison, $index);

            $conditions[] = [] === $equalities
                ? $tail
                : '(' . \implode(' AND ', $equalities) . ' AND ' . $tail . ')';

            $queryBuilder->setParameter('keyset_' . $index, $criteria->keyset->values[$sort->field]);
        }

        $queryBuilder->andWhere('(' . \implode(' OR ', $conditions) . ')');
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     * @throws Exception if the entity repository is not a DoctrineRepository
     */
    protected function sortFilters(
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

    /**
     * @throws Exception if a join has an unrecognised join type or a null alias
     */
    protected function attachJoins(
        QueryBuilder $queryBuilder,
        JoinCollection $joinCollection,
    ): void {
        foreach ($joinCollection->getJoins() as $join) {
            $alias = $join->getAlias();

            if (null === $alias) {
                throw new Exception('join alias must not be null');
            }

            switch ($join->getJoinType()) {
                case static::JOIN_INNER:
                    $queryBuilder->innerJoin(
                        $join->getJoin(),
                        $alias,
                        $join->getConditionType(),
                        $join->getCondition(),
                        $join->getIndexBy(),
                    );
                    break;
                case static::JOIN_LEFT:
                    $queryBuilder->leftJoin(
                        $join->getJoin(),
                        $alias,
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

    /**
     * @throws Exception if the entity repository is not a DoctrineRepository
     */
    protected function getDoctrineRepository(
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

    /**
     * @param array<string, mixed> $filters
     * @throws Exception if the method has not been overridden
     */
    protected function attachCustomFilters(
        QueryBuilder $queryBuilder,
        array $filters,
    ): JoinCollection {
        throw new Exception(
            \sprintf('override `%s` in `%s`', __METHOD__, static::class),
        );
    }

    /**
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

    /**
     * @param array<string, mixed> $filters
     * @throws Exception if an empty array filter is encountered and the behavior is set to ThrowException
     */
    protected function attachGenericFilters(
        QueryBuilder $queryBuilder,
        array $filters,
    ): void {
        /* filter names reach DQL by interpolation: sortFilters() only passes keys hasField() confirmed as mapped fields */
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

                $this->attachArrayFilter($queryBuilder, $filterName, $filterValue);
                continue;
            }

            $queryBuilder->andWhere(static::getAlias() . ".{$filterName} = :{$filterName}")
                ->setParameter($filterName, $filterValue);
        }
    }

    /**
     * @param non-empty-array<array-key, mixed> $filterValue
     */
    protected function attachArrayFilter(
        QueryBuilder $queryBuilder,
        string $filterName,
        array $filterValue,
    ): void {
        $queryBuilder->andWhere(static::getAlias() . ".{$filterName} IN (:{$filterName})");

        [$databaseValues, $parameterType] = $this->convertArrayFilterValues($filterName, $filterValue);

        $queryBuilder->setParameter($filterName, $databaseValues, $parameterType);
    }

    /**
     * The single conversion pipeline behind every `IN` list, whichever filter API built it.
     *
     * @param non-empty-array<array-key, mixed> $filterValue
     *
     * @return array{0: array<array-key, mixed>, 1: ArrayParameterType|null}
     */
    protected function convertArrayFilterValues(string $filterName, array $filterValue): array
    {
        $manager = $this->managerRegistry->getManagerForClass($this->getEntityClass());

        if (false === ($manager instanceof EntityManagerInterface)) {
            return [$filterValue, null];
        }

        $classMetadata = $manager->getClassMetadata($this->getEntityClass());
        $fieldType = $classMetadata->getTypeOfField($filterName);

        if (false === $classMetadata->hasField($filterName) || null === $fieldType) {
            return [$filterValue, null];
        }

        $doctrineType = Type::getType($fieldType);
        $platform = $manager->getConnection()->getDatabasePlatform();

        if (ParameterType::BINARY === $doctrineType->getBindingType()) {
            try {
                return [
                    \array_map(
                        static fn(mixed $value): mixed => $doctrineType->convertToDatabaseValue($value, $platform),
                        \array_values($filterValue),
                    ),
                    ArrayParameterType::BINARY,
                ];
            } catch (ConversionException) {
                /* falls through to the untyped binding below */
            }
        }

        /* DBAL has no array parameter type for dates, so an object bound inside an array reaches the driver unconverted */
        if (true === $this->isDateTimeArrayColumn($doctrineType)) {
            try {
                return [
                    \array_map(
                        fn(mixed $value): mixed => $this->parseDateTimeArrayFilterValue($value, $doctrineType, $platform),
                        \array_values($filterValue),
                    ),
                    ArrayParameterType::STRING,
                ];
            } catch (ConversionException) {
                /* falls through to the untyped binding below */
            }
        }

        /* uid types bind as STRING and so miss the binary branch, yet without a native GUID type they are stored as BINARY(16) */
        if (true === $this->isUidArrayColumn($doctrineType)) {
            try {
                return [
                    \array_map(
                        static fn(mixed $value): mixed => $doctrineType->convertToDatabaseValue($value, $platform),
                        \array_values($filterValue),
                    ),
                    ArrayParameterType::STRING,
                ];
            } catch (ConversionException) {
                /* falls through to the untyped binding below */
            } catch (Error $error) {
                /* a uid type without getName() raises Error where DBAL 4 rejects an element; one that declares it can only raise Error through a bug of its own, which must surface */
                if (true === \method_exists($doctrineType, 'getName')) {
                    throw $error;
                }
            }
        }

        return [$filterValue, null];
    }

    protected function isDateTimeArrayColumn(Type $doctrineType): bool
    {
        return $doctrineType instanceof PhpDateMappingType
            || $doctrineType instanceof PhpDateTimeMappingType
            || $doctrineType instanceof PhpTimeMappingType
            || $doctrineType instanceof DateIntervalType;
    }

    protected function isUidArrayColumn(Type $doctrineType): bool
    {
        /* symfony/doctrine-bridge is not a runtime dependency; the guard only makes explicit what instanceof already does without it */
        return true === \class_exists(AbstractUidType::class)
            && $doctrineType instanceof AbstractUidType;
    }

    /**
     * @throws ConversionException if the field's Doctrine type cannot convert the value even after matching its mutability
     */
    protected function parseDateTimeArrayFilterValue(
        mixed $value,
        Type $doctrineType,
        AbstractPlatform $platform,
    ): mixed {
        if (false === ($value instanceof DateTimeInterface) && false === ($value instanceof DateInterval)) {
            return $value;
        }

        try {
            return $doctrineType->convertToDatabaseValue($value, $platform);
        } catch (ConversionException $conversionException) {
            /* mutable date types reject DateTimeImmutable and vice versa, so flip the mutability keeping the same instant */
            if (true === $value instanceof DateTimeImmutable) {
                return $doctrineType->convertToDatabaseValue(DateTime::createFromInterface($value), $platform);
            }

            if (true === $value instanceof DateTime) {
                return $doctrineType->convertToDatabaseValue(DateTimeImmutable::createFromInterface($value), $platform);
            }

            throw $conversionException;
        }
    }

    /**
     * @throws Exception if the empty array filter behavior is set to ThrowException
     */
    protected function handleEmptyArrayFilter(
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

                /* always-false literal by design: matches nothing without binding a parameter, and the name stays visible in query logs */
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
    protected function getFlag(string $flagClass, UnitEnum $default): UnitEnum
    {
        $flag = $this->getFlags()[$flagClass] ?? null;

        if (false === ($flag instanceof $flagClass)) {
            return $default;
        }

        return $flag;
    }
}
