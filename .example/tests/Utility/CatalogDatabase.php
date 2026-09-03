<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Example\Test\Utility;

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
use Doctrine\ORM\Tools\SchemaTool;
use PrecisionSoft\Doctrine\Utility\Example\Entity\Category;
use PrecisionSoft\Doctrine\Utility\Example\Entity\Currency;
use PrecisionSoft\Doctrine\Utility\Example\Entity\Product;
use PrecisionSoft\Doctrine\Utility\Function\DateFormat;
use PrecisionSoft\Doctrine\Utility\Function\JsonContains;
use PrecisionSoft\Doctrine\Utility\Function\JsonContainsPath;
use PrecisionSoft\Doctrine\Utility\Function\JsonExtract;
use PrecisionSoft\Doctrine\Utility\Function\JsonSearch;
use PrecisionSoft\Doctrine\Utility\Function\JsonUnquote;
use Symfony\Bridge\Doctrine\Types\UuidType;

/** @internal */
final class CatalogDatabase
{
    /** @return iterable<string, array{string}> */
    public static function dataProviderMySqlEngine(): iterable
    {
        yield 'mysql' => ['DATABASE_URL_MYSQL'];
        yield 'mariadb' => ['DATABASE_URL_MARIADB'];
    }

    /** @return iterable<string, array{string}> */
    public static function dataProviderEveryEngine(): iterable
    {
        yield from static::dataProviderMySqlEngine();

        yield 'postgresql' => ['DATABASE_URL_POSTGRESQL'];
    }

    /** @throws SkipException when the engine is not there, so the caller skips instead of failing for the wrong reason */
    public static function connect(string $environmentVariable): Connection
    {
        $databaseUrl = \getenv($environmentVariable);

        if (false === \is_string($databaseUrl) || '' === $databaseUrl) {
            throw new SkipException(\sprintf('`%s` is not set - this suite expects the dev container', $environmentVariable));
        }

        $connection = DriverManager::getConnection(
            (new DsnParser(['mysql' => 'pdo_mysql', 'mariadb' => 'pdo_mysql', 'postgresql' => 'pdo_pgsql']))->parse($databaseUrl),
        );

        try {
            $connection->executeQuery('SELECT 1');
        } catch (DbalException $dbalException) {
            throw new SkipException(\sprintf(
                'cannot reach the database behind `%s` (%s) - start it with `./dc --profile db up -d`',
                $environmentVariable,
                $dbalException->getMessage(),
            ));
        }

        return $connection;
    }

    /**
     * The entity manager an application gets from the bundle: attribute mappings, the uid type, and the six DQL functions the README registers.
     */
    public static function createEntityManager(Connection $connection): EntityManagerInterface
    {
        if (false === Type::hasType(UuidType::NAME)) {
            Type::addType(UuidType::NAME, UuidType::class);
        }

        $configuration = new Configuration();
        $configuration->setMetadataDriverImpl(new AttributeDriver([\dirname(__DIR__, 2) . '/src/Entity']));
        $configuration->setProxyDir(\sys_get_temp_dir() . '/precision-soft-doctrine-utility-example-proxies');
        $configuration->setProxyNamespace('PrecisionSoftDoctrineUtilityExampleProxies');
        $configuration->setAutoGenerateProxyClasses(true);
        $configuration->setCustomStringFunctions([
            'JSON_CONTAINS' => JsonContains::class,
            'JSON_CONTAINS_PATH' => JsonContainsPath::class,
            'JSON_EXTRACT' => JsonExtract::class,
            'JSON_SEARCH' => JsonSearch::class,
            'JSON_UNQUOTE' => JsonUnquote::class,
            'DATE_FORMAT' => DateFormat::class,
        ]);

        return new EntityManager($connection, $configuration);
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
            $entityManager->getClassMetadata(Product::class),
            $entityManager->getClassMetadata(Category::class),
            $entityManager->getClassMetadata(Currency::class),
        ];
    }
}
