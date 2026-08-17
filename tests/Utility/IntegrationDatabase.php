<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Test\Utility;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Tools\SchemaTool;
use PrecisionSoft\Doctrine\Utility\Function\DateFormat;
use PrecisionSoft\Doctrine\Utility\Function\JsonContains;
use PrecisionSoft\Doctrine\Utility\Function\JsonContainsPath;
use PrecisionSoft\Doctrine\Utility\Function\JsonExtract;
use PrecisionSoft\Doctrine\Utility\Function\JsonSearch;
use PrecisionSoft\Doctrine\Utility\Function\JsonUnquote;
use PrecisionSoft\Doctrine\Utility\Test\Repository\Fixture\BinaryUuidType;
use PrecisionSoft\Doctrine\Utility\Test\Utility\Entity\FilterSubject;
use PrecisionSoft\Doctrine\Utility\Test\Utility\Entity\FilterSubjectTag;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Bridge\Doctrine\Types\UuidType;

/**
 * `DATABASE_URL_*` is exported whether or not the `db` profile runs, so connect and skip, never branch on it.
 *
 * @internal
 */
final class IntegrationDatabase
{
    /** @return iterable<string, array{string}> */
    public static function dataProviderEngine(): iterable
    {
        yield 'mysql' => ['DATABASE_URL_MYSQL'];
        yield 'mariadb' => ['DATABASE_URL_MARIADB'];
    }

    /**
     * @throws SkipIntegrationException when the database is unreachable, so the caller can skip rather than fail
     */
    public static function createConnection(string $environmentVariable): Connection
    {
        $databaseUrl = \getenv($environmentVariable);

        if (false === $databaseUrl || '' === $databaseUrl) {
            throw new SkipIntegrationException(\sprintf(
                '`%s` is not set — this suite expects the dev container from `.dev/docker/`',
                $environmentVariable,
            ));
        }

        /* the scheme map is required: a bare `mysql://` DSN resolves to no driver. Parsing stays outside the try — only an unreachable server may become a skip */
        $connection = DriverManager::getConnection(
            (new DsnParser(['mysql' => 'pdo_mysql', 'mariadb' => 'pdo_mysql']))->parse($databaseUrl),
        );

        try {
            $connection->executeQuery('SELECT 1');
        } catch (DbalException $dbalException) {
            throw new SkipIntegrationException(\sprintf(
                'cannot reach the database behind `%s` (%s) — start it with `./dc --profile db up -d`',
                $environmentVariable,
                $dbalException->getMessage(),
            ));
        }

        return $connection;
    }

    /* not ORMSetup::createAttributeMetadataConfiguration(): it hard-requires symfony/cache, which this library does not depend on */
    public static function createEntityManager(Connection $connection): EntityManagerInterface
    {
        $configuration = new Configuration();
        $configuration->setMetadataDriverImpl(new AttributeDriver([__DIR__ . '/Entity']));
        $configuration->setProxyDir(\sys_get_temp_dir() . '/precision-soft-doctrine-utility-proxies');
        $configuration->setProxyNamespace('PrecisionSoftDoctrineUtilityTestProxies');
        $configuration->setAutoGenerateProxyClasses(true);
        $configuration->setCustomStringFunctions(static::getCustomStringFunctions());

        return new EntityManager($connection, $configuration);
    }

    /**
     * String functions, as the README's `doctrine.yaml` tells a consumer to register them: a mismatch is a finding.
     *
     * @return array<string, class-string<FunctionNode>>
     */
    public static function getCustomStringFunctions(): array
    {
        return [
            'JSON_CONTAINS' => JsonContains::class,
            'JSON_CONTAINS_PATH' => JsonContainsPath::class,
            'JSON_EXTRACT' => JsonExtract::class,
            'JSON_SEARCH' => JsonSearch::class,
            'JSON_UNQUOTE' => JsonUnquote::class,
            'DATE_FORMAT' => DateFormat::class,
        ];
    }

    /**
     * The type registry is global and a second `addType()` for one name throws, so every registration is guarded.
     */
    public static function registerTypes(): void
    {
        $types = [
            UuidType::NAME => UuidType::class,
            UlidType::NAME => UlidType::class,
            BinaryUuidType::NAME => BinaryUuidType::class,
        ];

        foreach ($types as $typeName => $typeClass) {
            if (false === Type::hasType($typeName)) {
                Type::addType($typeName, $typeClass);
            }
        }
    }

    public static function createSchema(EntityManagerInterface $entityManager): void
    {
        $schemaTool = new SchemaTool($entityManager);

        $schemaTool->dropSchema(static::getClassMetadata($entityManager));
        $schemaTool->createSchema(static::getClassMetadata($entityManager));
    }

    public static function dropSchema(EntityManagerInterface $entityManager): void
    {
        (new SchemaTool($entityManager))->dropSchema(static::getClassMetadata($entityManager));
    }

    /** @return list<ClassMetadata<object>> */
    private static function getClassMetadata(EntityManagerInterface $entityManager): array
    {
        return [
            $entityManager->getClassMetadata(FilterSubject::class),
            $entityManager->getClassMetadata(FilterSubjectTag::class),
        ];
    }
}
