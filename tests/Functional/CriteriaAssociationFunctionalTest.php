<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Test\Functional;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PrecisionSoft\Doctrine\Utility\Exception\Exception;
use PrecisionSoft\Doctrine\Utility\Repository\Criteria\Criteria;
use PrecisionSoft\Doctrine\Utility\Repository\Criteria\Direction;
use PrecisionSoft\Doctrine\Utility\Repository\Criteria\Filter;
use PrecisionSoft\Doctrine\Utility\Repository\Criteria\Keyset;
use PrecisionSoft\Doctrine\Utility\Repository\Criteria\Operator;
use PrecisionSoft\Doctrine\Utility\Repository\Criteria\Sort;
use PrecisionSoft\Doctrine\Utility\Test\Utility\Entity\FilterSubject;
use PrecisionSoft\Doctrine\Utility\Test\Utility\Entity\FilterSubjectTag;
use PrecisionSoft\Doctrine\Utility\Test\Utility\FilterSubjectTagRepository;
use PrecisionSoft\Doctrine\Utility\Test\Utility\IntegrationDatabase;
use PrecisionSoft\Doctrine\Utility\Test\Utility\IntegrationManagerRegistry;
use PrecisionSoft\Doctrine\Utility\Test\Utility\SkipIntegrationException;

/**
 * DQL takes an owning side association wherever its foreign key fits — a comparison, a list, a sort, a keyset — and
 * refuses it under LIKE only; the criteria API is expected to follow DQL, not to narrow it.
 *
 * @internal
 */
#[Group('integration')]
final class CriteriaAssociationFunctionalTest extends TestCase
{
    private ?EntityManagerInterface $entityManager = null;

    /** @var array<string, int> */
    private array $identifiers = [];

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderPortableEngine')]
    public function testAnAssociationTakesAComparisonAListASortAndAKeyset(string $environmentVariable): void
    {
        $repository = $this->boot($environmentVariable);
        $sorts = [new Sort('filterSubject', Direction::Ascending), new Sort('id', Direction::Ascending)];

        static::assertSame(
            [$this->identifiers['first']],
            $repository->findIdsByCriteria(new Criteria(
                filters: [new Filter('filterSubject', Operator::Equal, $this->identifiers['alpha'])],
            )),
        );
        static::assertSame(
            [$this->identifiers['second']],
            $repository->findIdsByCriteria(new Criteria(
                filters: [new Filter('filterSubject', Operator::GreaterThan, $this->identifiers['alpha'])],
            )),
        );
        static::assertSame(
            [$this->identifiers['first'], $this->identifiers['second']],
            $repository->findIdsByCriteria(new Criteria(
                filters: [new Filter('filterSubject', Operator::In, [$this->identifiers['alpha'], $this->identifiers['beta']])],
                sorts: $sorts,
            )),
        );
        static::assertSame(
            [$this->identifiers['second']],
            $repository->findIdsByCriteria(new Criteria(
                sorts: $sorts,
                keyset: new Keyset(['filterSubject' => $this->identifiers['alpha'], 'id' => $this->identifiers['first']]),
            )),
        );
    }

    #[DataProviderExternal(IntegrationDatabase::class, 'dataProviderPortableEngine')]
    public function testALikeOnAnAssociationIsRefusedBeforeAnyQuery(string $environmentVariable): void
    {
        $repository = $this->boot($environmentVariable);

        /* left to DQL this is a semantical error at execution: "Invalid PathExpression. Must be a StateFieldPathExpression." */
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('criteria operator `LIKE` cannot apply to the association `filterSubject`');

        $repository->findIdsByCriteria(new Criteria(
            filters: [new Filter('filterSubject', Operator::Like, '%x%')],
        ));
    }

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

    private function boot(string $environmentVariable): FilterSubjectTagRepository
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

        $alpha = (new FilterSubject())->setLabel('alpha');
        $beta = (new FilterSubject())->setLabel('beta');
        $first = (new FilterSubjectTag())->setName('first')->setFilterSubject($alpha);
        $second = (new FilterSubjectTag())->setName('second')->setFilterSubject($beta);

        foreach ([$alpha, $beta, $first, $second] as $entity) {
            $entityManager->persist($entity);
        }

        $entityManager->flush();

        $this->identifiers = [
            'alpha' => (int)$alpha->getId(),
            'beta' => (int)$beta->getId(),
            'first' => (int)$first->getId(),
            'second' => (int)$second->getId(),
        ];

        $entityManager->clear();

        return (new FilterSubjectTagRepository())
            ->setManagerRegistry(new IntegrationManagerRegistry($entityManager));
    }
}
