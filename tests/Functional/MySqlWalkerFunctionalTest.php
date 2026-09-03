<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Test\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PrecisionSoft\Doctrine\Utility\Exception\Exception;
use PrecisionSoft\Doctrine\Utility\Test\Utility\Entity\FilterSubject;
use PrecisionSoft\Doctrine\Utility\Test\Utility\Entity\FilterSubjectTag;
use PrecisionSoft\Doctrine\Utility\Test\Utility\IntegrationDatabase;
use PrecisionSoft\Doctrine\Utility\Test\Utility\SkipIntegrationException;
use PrecisionSoft\Doctrine\Utility\Walker\MySqlWalker;

/**
 * `EXPLAIN` is read back: a misplaced hint still produces SQL that runs while selecting no index at all.
 *
 * @internal
 */
#[Group('integration')]
final class MySqlWalkerFunctionalTest extends TestCase
{
    private const SUBJECT_INDEX = 'idx_filter_subject_label';

    private ?EntityManagerInterface $entityManager = null;

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testUseIndexHintExecutesAndTheServerPicksTheNamedIndex(string $environmentVariable): void
    {
        $entityManager = $this->boot($environmentVariable);

        $query = $this->createLabelQuery($entityManager);
        $query->setHint(MySqlWalker::HINT_USE_INDEX, static::SUBJECT_INDEX);

        static::assertSame(['alpha'], $this->fetchLabels($query));
        static::assertStringContainsString(static::SUBJECT_INDEX, $this->explain($entityManager, $query));
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testForceIndexHintExecutesAndTheServerPicksTheNamedIndex(string $environmentVariable): void
    {
        $entityManager = $this->boot($environmentVariable);

        $query = $this->createLabelQuery($entityManager);
        $query->setHint(MySqlWalker::HINT_FORCE_INDEX, static::SUBJECT_INDEX);

        static::assertSame(['alpha'], $this->fetchLabels($query));
        static::assertStringContainsString(static::SUBJECT_INDEX, $this->explain($entityManager, $query));
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testIgnoreIndexHintExecutesAndTheServerAvoidsTheNamedIndex(string $environmentVariable): void
    {
        $entityManager = $this->boot($environmentVariable);

        $query = $this->createLabelQuery($entityManager);
        $query->setHint(MySqlWalker::HINT_IGNORE_INDEX, static::SUBJECT_INDEX);

        static::assertSame(['alpha'], $this->fetchLabels($query));
        static::assertStringNotContainsString(
            static::SUBJECT_INDEX,
            $this->explain($entityManager, $query),
            'IGNORE INDEX must take the index out of the plan, not merely parse',
        );
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testSelectForUpdateHintProducesALockingRead(string $environmentVariable): void
    {
        $entityManager = $this->boot($environmentVariable);

        $query = $this->createLabelQuery($entityManager);
        $query->setHint(MySqlWalker::HINT_SELECT_FOR_UPDATE, true);

        static::assertStringEndsWith(' FOR UPDATE', $this->getSql($query));

        $entityManager->getConnection()->beginTransaction();

        try {
            static::assertSame(['alpha'], $this->fetchLabels($query));
        } finally {
            $entityManager->getConnection()->rollBack();
        }
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testIgnoreIndexOnJoinHintExecutesAgainstARealJoin(string $environmentVariable): void
    {
        $entityManager = $this->boot($environmentVariable);

        $query = $entityManager->createQuery(
            'SELECT fst.name FROM ' . FilterSubjectTag::class . ' fst'
            . ' JOIN fst.filterSubject fs'
            . " WHERE fst.name = 'first-tag' ORDER BY fst.name ASC",
        );
        $query->setHint(Query::HINT_CUSTOM_OUTPUT_WALKER, MySqlWalker::class);

        /* the index must belong to the joined table, not the root one: anything else is server error 1176 */
        $query->setHint(MySqlWalker::HINT_IGNORE_INDEX_ON_JOIN, [static::SUBJECT_INDEX, 'filter_subject']);

        static::assertStringContainsString('IGNORE INDEX (' . static::SUBJECT_INDEX . ') ON', $this->getSql($query));
        static::assertSame(
            [['name' => 'first-tag']],
            $query->getArrayResult(),
            'the rewritten join must still return the same rows the unhinted query would',
        );
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testAnInjectedIdentifierIsRejectedBeforeItReachesTheServer(string $environmentVariable): void
    {
        $entityManager = $this->boot($environmentVariable);

        $query = $this->createLabelQuery($entityManager);
        $query->setHint(MySqlWalker::HINT_USE_INDEX, static::SUBJECT_INDEX . ') -- ');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('invalid identifier');

        $query->getSQL();
    }

    protected function tearDown(): void
    {
        if (null !== $this->entityManager) {
            IntegrationDatabase::dropSchema($this->entityManager);
            $this->entityManager->getConnection()->close();
            $this->entityManager = null;
        }

        parent::tearDown();
    }

    private function createLabelQuery(EntityManagerInterface $entityManager): Query
    {
        $query = $entityManager->createQuery(
            'SELECT fs.label FROM ' . FilterSubject::class . " fs WHERE fs.label = 'alpha'",
        );
        $query->setHint(Query::HINT_CUSTOM_OUTPUT_WALKER, MySqlWalker::class);

        return $query;
    }

    private function getSql(Query $query): string
    {
        $sql = $query->getSQL();

        return true === \is_array($sql) ? \implode('; ', $sql) : $sql;
    }

    /** @return array<int, string> */
    private function fetchLabels(Query $query): array
    {
        /** @var array<int, array{label: string}> $rows */
        $rows = $query->getArrayResult();

        return \array_map(static fn(array $row): string => $row['label'], $rows);
    }

    private function explain(EntityManagerInterface $entityManager, Query $query): string
    {
        $rows = $entityManager->getConnection()
            ->executeQuery('EXPLAIN ' . $this->getSql($query))
            ->fetchAllAssociative();

        return \json_encode($rows, \JSON_THROW_ON_ERROR);
    }

    private function boot(string $environmentVariable): EntityManagerInterface
    {
        IntegrationDatabase::registerTypes();

        try {
            $connection = IntegrationDatabase::createConnection($environmentVariable);
        } catch (SkipIntegrationException $skipIntegrationException) {
            static::markTestSkipped($skipIntegrationException->getMessage());
        }

        $entityManager = IntegrationDatabase::createEntityManager($connection);
        $this->entityManager = $entityManager;

        IntegrationDatabase::createSchema($entityManager);
        $this->seed($entityManager);

        return $entityManager;
    }

    private function seed(EntityManagerInterface $entityManager): void
    {
        foreach (['alpha', 'beta', 'gamma'] as $index => $label) {
            $filterSubject = (new FilterSubject())->setLabel($label);
            $entityManager->persist($filterSubject);

            $filterSubjectTag = (new FilterSubjectTag())
                ->setName(0 === $index ? 'first-tag' : $label . '-tag')
                ->setFilterSubject($filterSubject);
            $entityManager->persist($filterSubjectTag);
        }

        $entityManager->flush();
        $entityManager->clear();
    }
}
