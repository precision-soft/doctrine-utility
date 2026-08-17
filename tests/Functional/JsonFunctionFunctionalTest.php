<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Test\Functional;

use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PrecisionSoft\Doctrine\Utility\Test\Utility\Entity\FilterSubject;
use PrecisionSoft\Doctrine\Utility\Test\Utility\IntegrationDatabase;
use PrecisionSoft\Doctrine\Utility\Test\Utility\SkipIntegrationException;

/**
 * @internal
 */
#[Group('integration')]
final class JsonFunctionFunctionalTest extends TestCase
{
    private ?EntityManagerInterface $entityManager = null;

    /** @var array<string, int> */
    private array $identifiers = [];

    protected function tearDown(): void
    {
        if (null !== $this->entityManager) {
            IntegrationDatabase::dropSchema($this->entityManager);
            $this->entityManager->getConnection()->close();
            $this->entityManager = null;
        }

        $this->identifiers = [];

        parent::tearDown();
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testJsonContainsMatchesAValueAtAPath(string $environmentVariable): void
    {
        $entityManager = $this->boot($environmentVariable);

        static::assertSame(
            [$this->identifiers['alpha'], $this->identifiers['gamma']],
            $this->selectIds(
                $entityManager,
                "SELECT fs.id FROM " . FilterSubject::class . " fs WHERE JSON_CONTAINS(fs.payload, '\"red\"', '$.tags') = 1 ORDER BY fs.id ASC",
            ),
        );
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testJsonContainsWithoutAPathMatchesTheWholeDocument(string $environmentVariable): void
    {
        $entityManager = $this->boot($environmentVariable);

        static::assertSame(
            [$this->identifiers['beta']],
            $this->selectIds(
                $entityManager,
                "SELECT fs.id FROM " . FilterSubject::class . " fs WHERE JSON_CONTAINS(fs.payload, '{\"level\": 2}', '$.nested') = 1 ORDER BY fs.id ASC",
            ),
        );
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testJsonContainsPathHonoursOneAndAllModes(string $environmentVariable): void
    {
        $entityManager = $this->boot($environmentVariable);

        static::assertSame(
            [$this->identifiers['alpha'], $this->identifiers['beta']],
            $this->selectIds(
                $entityManager,
                "SELECT fs.id FROM " . FilterSubject::class . " fs WHERE JSON_CONTAINS_PATH(fs.payload, 'one', '$.nested') = 1 ORDER BY fs.id ASC",
            ),
        );

        static::assertSame(
            [$this->identifiers['gamma']],
            $this->selectIds(
                $entityManager,
                "SELECT fs.id FROM " . FilterSubject::class . " fs WHERE JSON_CONTAINS_PATH(fs.payload, 'all', '$.tags', '$.other') = 1 ORDER BY fs.id ASC",
            ),
            "'all' requires every path to be present, so only the row carrying both may match",
        );
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testJsonExtractReturnsTheValueAtAPath(string $environmentVariable): void
    {
        $entityManager = $this->boot($environmentVariable);

        $rows = $entityManager
            ->createQuery(
                "SELECT fs.id AS id, JSON_EXTRACT(fs.payload, '$.nested.level') AS level FROM " . FilterSubject::class
                . " fs WHERE JSON_CONTAINS_PATH(fs.payload, 'one', '$.nested') = 1 ORDER BY fs.id ASC",
            )
            ->getArrayResult();

        static::assertSame(['1', '2'], \array_map(static fn(array $row): string => (string)$row['level'], $rows));
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testJsonSearchFindsAStringAndJsonUnquoteStripsTheQuotes(string $environmentVariable): void
    {
        $entityManager = $this->boot($environmentVariable);

        $rows = $entityManager
            ->createQuery(
                "SELECT fs.id AS id, JSON_UNQUOTE(JSON_SEARCH(fs.payload, 'one', 'blue')) AS path FROM " . FilterSubject::class
                . " fs WHERE fs.label = 'beta'",
            )
            ->getArrayResult();

        static::assertCount(1, $rows);
        static::assertSame('$.tags[0]', $rows[0]['path'], 'JSON_SEARCH returns the path as a quoted json string; JSON_UNQUOTE is what makes it comparable');
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testJsonSearchInAllModeReturnsEveryMatch(string $environmentVariable): void
    {
        $entityManager = $this->boot($environmentVariable);

        static::assertSame(
            [$this->identifiers['alpha'], $this->identifiers['gamma']],
            $this->selectIds(
                $entityManager,
                "SELECT fs.id FROM " . FilterSubject::class . " fs WHERE JSON_SEARCH(fs.payload, 'all', 'red') IS NOT NULL ORDER BY fs.id ASC",
            ),
        );
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderEngine')]
    public function testDateFormatFormatsARealColumn(string $environmentVariable): void
    {
        $entityManager = $this->boot($environmentVariable);

        $rows = $entityManager
            ->createQuery(
                "SELECT fs.id AS id, DATE_FORMAT(fs.dateTimeValue, '%Y-%m') AS period FROM " . FilterSubject::class
                . ' fs ORDER BY fs.id ASC',
            )
            ->getArrayResult();

        static::assertSame(
            ['2026-01', '2026-02', '2026-03'],
            \array_map(static fn(array $row): string => (string)$row['period'], $rows),
        );
    }

    /** @return array<int, int> */
    private function selectIds(EntityManagerInterface $entityManager, string $dql): array
    {
        /** @var array<int, array{id: int}> $rows */
        $rows = $entityManager->createQuery($dql)->getArrayResult();

        return \array_map(static fn(array $row): int => $row['id'], $rows);
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
        $alpha = (new FilterSubject())
            ->setLabel('alpha')
            ->setDateTimeValue(new DateTime('2026-01-01 10:00:00'))
            ->setPayload(['tags' => ['red', 'green'], 'nested' => ['level' => 1]]);

        $beta = (new FilterSubject())
            ->setLabel('beta')
            ->setDateTimeValue(new DateTime('2026-02-02 11:00:00'))
            ->setPayload(['tags' => ['blue'], 'nested' => ['level' => 2]]);

        $gamma = (new FilterSubject())
            ->setLabel('gamma')
            ->setDateTimeValue(new DateTime('2026-03-03 12:00:00'))
            ->setPayload(['tags' => ['red'], 'other' => 'value']);

        foreach ([$alpha, $beta, $gamma] as $filterSubject) {
            $entityManager->persist($filterSubject);
        }

        $entityManager->flush();

        $this->identifiers = [
            'alpha' => (int)$alpha->getId(),
            'beta' => (int)$beta->getId(),
            'gamma' => (int)$gamma->getId(),
        ];

        $entityManager->clear();
    }
}
