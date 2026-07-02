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
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\ParameterType;
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
     * The default implementation throws intentionally — it acts as a soft-abstract to signal
     * that custom filters must be handled by the subclass when they are present.
     *
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
     * Each entry maps a flag enum class to one of its cases; absent flags fall back to abstract repository defaults.
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

    /**
     * @param array<string, mixed> $filters
     * @throws Exception if an empty array filter is encountered and the behavior is set to ThrowException
     */
    protected function attachGenericFilters(
        QueryBuilder $queryBuilder,
        array $filters,
    ): void {
        /** @info filter names are interpolated into DQL but cannot be user-supplied: sortFilters() only passes keys here after hasField() confirms them as mapped entity fields */
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
     * Binds an array filter as an `IN (...)` clause, choosing the binding in this order:
     *
     * 1. Binary-bound columns (e.g. a `uuid` stored as BINARY(16)) convert every value with the field's type
     *    and bind as `ArrayParameterType::BINARY`, so they match instead of being bound as their string
     *    representation.
     * 2. Date/time/interval columns (`date`, `datetime`, `datetimetz`, `time`, their immutable variants, and
     *    `dateinterval`) are the only genuine gap: Doctrine converts a single value through the field type, but
     *    DBAL has no array parameter type for dates, so an object bound inside an array reaches the driver
     *    unconverted and fails to stringify. The field's Doctrine type — not the params — selects this branch;
     *    each value is then parsed, with date/time objects converted to the column's scalar representation and
     *    strings/other scalars left untouched, so the column accepts both `'2026-07-04'` and
     *    `new DateTime('2026-07-04')`. The array is bound as `ArrayParameterType::STRING`. If any element cannot
     *    be converted (a value the type rejects even after matching mutability), the filter falls through to
     *    the default binding, so this can never bind worse than before.
     * 3. Symfony uid columns (`uuid`/`ulid` via `AbstractUidType`) bind as `ParameterType::STRING`, so they miss
     *    the binary branch, yet on platforms without a native GUID type they are stored as BINARY(16) — an
     *    `AbstractUid` bound untyped reaches the driver as its string representation (36-character RFC 4122 for
     *    `uuid`, 26-character base32 for `ulid`) and silently matches nothing. The field's Doctrine type — not the params — selects this branch; every value is
     *    converted through the uid type (which accepts both `AbstractUid` objects and RFC 4122 strings) and the
     *    array is bound as `ArrayParameterType::STRING`, matching what the persister does for single values on
     *    every platform. If any element cannot be converted, the filter falls through to the default binding,
     *    so this can never bind worse than before. An `Error` raised by a uid type that declares `getName()`
     *    signals a bug in the type's own overrides — not an unconvertible element — and is rethrown instead of
     *    falling through; only uid types without `getName()` (whose rejection path itself raises `Error` under
     *    DBAL 4) fall through on `Error`.
     * 4. Every other field keeps the untyped binding and lets Doctrine resolve the type as it does for single
     *    values — including unwrapping backed enums and binding scalar/string/int values from their raw
     *    representation. Associations, unmapped keys, and non-ORM managers land here too.
     *
     * @param non-empty-array<array-key, mixed> $filterValue
     */
    protected function attachArrayFilter(
        QueryBuilder $queryBuilder,
        string $filterName,
        array $filterValue,
    ): void {
        $queryBuilder->andWhere(static::getAlias() . ".{$filterName} IN (:{$filterName})");

        $manager = $this->managerRegistry->getManagerForClass($this->getEntityClass());

        /** @info without an ORM EntityManager the field type cannot be resolved; fall back to the untyped binding so non-ORM managers keep working as before */
        if (false === ($manager instanceof EntityManagerInterface)) {
            $queryBuilder->setParameter($filterName, $filterValue);

            return;
        }

        $classMetadata = $manager->getClassMetadata($this->getEntityClass());
        $fieldType = $classMetadata->getTypeOfField($filterName);

        if (false === $classMetadata->hasField($filterName) || null === $fieldType) {
            $queryBuilder->setParameter($filterName, $filterValue);

            return;
        }

        $doctrineType = Type::getType($fieldType);
        $platform = $manager->getConnection()->getDatabasePlatform();

        /** @info binary-bound columns (e.g. a uuid stored as BINARY(16)) must have every value converted and bound as BINARY to match instead of being bound as their string representation */
        if (ParameterType::BINARY === $doctrineType->getBindingType()) {
            $databaseValues = \array_map(
                static fn(mixed $value): mixed => $doctrineType->convertToDatabaseValue($value, $platform),
                \array_values($filterValue),
            );

            $queryBuilder->setParameter($filterName, $databaseValues, ArrayParameterType::BINARY);

            return;
        }

        /** @info date/time/interval columns are the only genuine gap: Doctrine converts a single value through the field type, but DBAL has no array parameter type for dates, so an object bound inside an array reaches the driver unconverted and fails to stringify. The field's Doctrine type — not the params — decides this: on such a column every value is parsed (date/time objects converted to the column's scalar representation, strings and other scalars left untouched) so a date column accepts both '2026-07-04' and new DateTime('2026-07-04'), and the array is bound as STRING (every date/time/interval type binds as STRING) */
        if (true === $this->isDateTimeArrayColumn($doctrineType)) {
            try {
                $databaseValues = \array_map(
                    fn(mixed $value): mixed => $this->parseDateTimeArrayFilterValue($value, $doctrineType, $platform),
                    \array_values($filterValue),
                );

                $queryBuilder->setParameter($filterName, $databaseValues, ArrayParameterType::STRING);

                return;
            } catch (ConversionException) {
                /** @info an element the field's Doctrine type rejects outright (even after matching mutability) — fall through to the untyped binding below so this never binds worse than before the date/time handling was added */
            }
        }

        /** @info Symfony uid columns bind as STRING so they miss the binary branch, yet without a native GUID type they are stored as BINARY(16) — an AbstractUid bound untyped reaches the driver as its string representation (36-character RFC 4122 for uuid, 26-character base32 for ulid) and silently matches nothing. The field's Doctrine type — not the params — decides this: every value is converted through the uid type (which accepts both AbstractUid objects and RFC 4122 strings) and the array is bound as STRING, exactly what the persister does for single values — the type yields bytes on platforms without a native GUID type and an RFC 4122 string on those with one */
        if (true === $this->isUidArrayColumn($doctrineType)) {
            try {
                $databaseValues = \array_map(
                    static fn(mixed $value): mixed => $doctrineType->convertToDatabaseValue($value, $platform),
                    \array_values($filterValue),
                );

                $queryBuilder->setParameter($filterName, $databaseValues, ArrayParameterType::STRING);

                return;
            } catch (ConversionException) {
                /** @info an element the uid type rejects — fall through to the untyped binding below so this never binds worse than before the uid handling was added */
            } catch (Error $error) {
                /** @info the bridge's rejection helpers call $this->getName(), which DBAL 4's Type no longer declares, so a uid type without getName() raises Error instead of ConversionException when it rejects an element — only those types fall through to the untyped binding below; a type that does declare getName() (Symfony's own UuidType/UlidType, DBAL 3-era consumer types) can only raise Error through a genuine bug (e.g. a broken getUidClass() or convertToDatabaseValue() override), which must surface instead of silently binding untyped and matching nothing */
                if (true === \method_exists($doctrineType, 'getName')) {
                    throw $error;
                }
            }
        }

        /** @info default: every other column keeps the untyped binding and lets Doctrine resolve the type exactly as it does for single values — including unwrapping backed enums and binding scalar/string/int values from their raw representation. Associations, unmapped keys, and non-ORM managers land here too; so does the fall-through when date/time or uid conversion fails */
        $queryBuilder->setParameter($filterName, $filterValue);
    }

    /**
     * A field is a date/time/interval column when its Doctrine type maps to a PHP date/time object — the case DBAL's
     * array parameter binding cannot convert on its own. Detected by the type's mapping interface (`date`, `datetime`,
     * `datetimetz`, `time` and their immutable variants) plus the interval type, never by inspecting the filter values.
     */
    private function isDateTimeArrayColumn(Type $doctrineType): bool
    {
        return $doctrineType instanceof PhpDateMappingType
            || $doctrineType instanceof PhpDateTimeMappingType
            || $doctrineType instanceof PhpTimeMappingType
            || $doctrineType instanceof DateIntervalType;
    }

    /**
     * A field is a uid column when its Doctrine type extends Symfony's `AbstractUidType` (`uuid`/`ulid`). Detected by
     * the field's type, never by inspecting the filter values. symfony/doctrine-bridge is not a runtime dependency of
     * this library; the `class_exists` guard only makes that explicit — `instanceof` against a class that is not
     * loaded evaluates to false without triggering autoloading, so the check is inert without the bridge either way.
     */
    private function isUidArrayColumn(Type $doctrineType): bool
    {
        return true === \class_exists(AbstractUidType::class)
            && $doctrineType instanceof AbstractUidType;
    }

    /**
     * @throws ConversionException if the field's Doctrine type cannot convert the value even after matching its mutability
     */
    private function parseDateTimeArrayFilterValue(
        mixed $value,
        Type $doctrineType,
        AbstractPlatform $platform,
    ): mixed {
        /** @info strings and other scalars (e.g. a raw 'YYYY-MM-DD' passed for a date column) are already bindable and pass through untouched; only date/time objects need converting to the column's scalar representation */
        if (false === ($value instanceof DateTimeInterface) && false === ($value instanceof DateInterval)) {
            return $value;
        }

        try {
            return $doctrineType->convertToDatabaseValue($value, $platform);
        } catch (ConversionException $conversionException) {
            /** @info mutable date types reject DateTimeImmutable and vice versa; flip the mutability to match the field's type, keeping the same instant, then convert to the canonical column representation */
            if ($value instanceof DateTimeImmutable) {
                return $doctrineType->convertToDatabaseValue(DateTime::createFromInterface($value), $platform);
            }

            if ($value instanceof DateTime) {
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

                /** @info always-false literal comparison by design — ensures the filter matches nothing without binding parameters, keeping the name visible in query logs */
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
