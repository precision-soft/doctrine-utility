<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Test\Repository;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\JoinColumnMapping;
use Doctrine\ORM\Mapping\ManyToOneAssociationMapping;
use Doctrine\ORM\Mapping\OneToManyAssociationMapping;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use PrecisionSoft\Doctrine\Utility\Repository\DoctrineRepository;
use PrecisionSoft\Doctrine\Utility\Test\Utility\Entity\FilterSubject;
use PrecisionSoft\Doctrine\Utility\Test\Utility\Entity\FilterSubjectTag;
use ReflectionClass;

/**
 * @internal
 */
final class DoctrineRepositoryTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testHasFieldReturnsTrueForDirectField(): void
    {
        $classMetadata = Mockery::mock(ClassMetadata::class);
        $classMetadata->shouldReceive('hasField')
            ->with('name')
            ->once()
            ->andReturn(true);

        $doctrineRepository = $this->createDoctrineRepositoryWithMetadata($classMetadata);

        static::assertSame(true, $doctrineRepository->hasField('name'));
    }

    public function testHasFieldReturnsTrueForOwningSideAssociation(): void
    {
        $classMetadata = Mockery::mock(ClassMetadata::class);
        $classMetadata->shouldReceive('hasField')
            ->with('category')
            ->once()
            ->andReturn(false);
        $classMetadata->shouldReceive('hasAssociation')
            ->with('category')
            ->once()
            ->andReturn(true);
        $classMetadata->shouldReceive('isAssociationInverseSide')
            ->with('category')
            ->once()
            ->andReturn(false);

        $doctrineRepository = $this->createDoctrineRepositoryWithMetadata($classMetadata);

        static::assertSame(true, $doctrineRepository->hasField('category'));
    }

    public function testHasFieldReturnsFalseForInverseSideAssociation(): void
    {
        $classMetadata = Mockery::mock(ClassMetadata::class);
        $classMetadata->shouldReceive('hasField')
            ->with('comments')
            ->once()
            ->andReturn(false);
        $classMetadata->shouldReceive('hasAssociation')
            ->with('comments')
            ->once()
            ->andReturn(true);
        $classMetadata->shouldReceive('isAssociationInverseSide')
            ->with('comments')
            ->once()
            ->andReturn(true);

        $doctrineRepository = $this->createDoctrineRepositoryWithMetadata($classMetadata);

        static::assertSame(false, $doctrineRepository->hasField('comments'));
    }

    public function testHasFieldReturnsFalseForNonExistentField(): void
    {
        $classMetadata = Mockery::mock(ClassMetadata::class);
        $classMetadata->shouldReceive('hasField')
            ->with('nonExistent')
            ->once()
            ->andReturn(false);
        $classMetadata->shouldReceive('hasAssociation')
            ->with('nonExistent')
            ->once()
            ->andReturn(false);

        $doctrineRepository = $this->createDoctrineRepositoryWithMetadata($classMetadata);

        static::assertSame(false, $doctrineRepository->hasField('nonExistent'));
    }

    public function testHasAssociationAsksTheMetadata(): void
    {
        $classMetadata = Mockery::mock(ClassMetadata::class);
        $classMetadata->shouldReceive('hasAssociation')
            ->with('category')
            ->once()
            ->andReturn(true);

        $doctrineRepository = $this->createDoctrineRepositoryWithMetadata($classMetadata);

        static::assertTrue($doctrineRepository->hasAssociation('category'));
    }

    public function testAllowsNullFollowsTheFieldMapping(): void
    {
        $classMetadata = Mockery::mock(ClassMetadata::class);
        $classMetadata->shouldReceive('hasField')
            ->with('deletedAt')
            ->andReturn(true);
        $classMetadata->shouldReceive('isNullable')
            ->with('deletedAt')
            ->once()
            ->andReturn(true);
        $classMetadata->shouldReceive('hasField')
            ->with('label')
            ->andReturn(true);
        $classMetadata->shouldReceive('isNullable')
            ->with('label')
            ->once()
            ->andReturn(false);

        $doctrineRepository = $this->createDoctrineRepositoryWithMetadata($classMetadata);

        static::assertTrue($doctrineRepository->allowsNull('deletedAt'));
        static::assertFalse($doctrineRepository->allowsNull('label'));
    }

    public function testAllowsNullReadsTheJoinColumnsOfAnOwningSideAssociation(): void
    {
        $required = new ManyToOneAssociationMapping('owner', FilterSubjectTag::class, FilterSubject::class);
        $required->joinColumns = [
            JoinColumnMapping::fromMappingArray(['name' => 'owner_id', 'referencedColumnName' => 'id', 'nullable' => false]),
        ];

        $optional = new ManyToOneAssociationMapping('parent', FilterSubject::class, FilterSubject::class);
        $optional->joinColumns = [
            JoinColumnMapping::fromMappingArray(['name' => 'parent_id', 'referencedColumnName' => 'id']),
        ];

        $classMetadata = Mockery::mock(ClassMetadata::class);
        $classMetadata->shouldReceive('hasField')
            ->andReturn(false);
        $classMetadata->shouldReceive('hasAssociation')
            ->andReturn(true);
        $classMetadata->shouldReceive('getAssociationMapping')
            ->with('owner')
            ->andReturn($required);
        $classMetadata->shouldReceive('getAssociationMapping')
            ->with('parent')
            ->andReturn($optional);

        $doctrineRepository = $this->createDoctrineRepositoryWithMetadata($classMetadata);

        static::assertFalse($doctrineRepository->allowsNull('owner'), 'every join column says nullable: false');
        static::assertTrue($doctrineRepository->allowsNull('parent'), 'a join column that says nothing is nullable, as the mapping defaults');
    }

    public function testAllowsNullIsFalseForAnInverseSideAssociationAndForAnUnmappedName(): void
    {
        $classMetadata = Mockery::mock(ClassMetadata::class);
        $classMetadata->shouldReceive('hasField')
            ->andReturn(false);
        $classMetadata->shouldReceive('hasAssociation')
            ->with('comments')
            ->andReturn(true);
        $classMetadata->shouldReceive('getAssociationMapping')
            ->with('comments')
            ->andReturn(new OneToManyAssociationMapping('comments', FilterSubject::class, FilterSubjectTag::class));
        $classMetadata->shouldReceive('hasAssociation')
            ->with('nonExistent')
            ->andReturn(false);

        $doctrineRepository = $this->createDoctrineRepositoryWithMetadata($classMetadata);

        static::assertFalse($doctrineRepository->allowsNull('comments'));
        static::assertFalse($doctrineRepository->allowsNull('nonExistent'));
    }

    /** @param ClassMetadata<object> $classMetadata */
    private function createDoctrineRepositoryWithMetadata(ClassMetadata $classMetadata): DoctrineRepository
    {
        $reflectionClass = new ReflectionClass(DoctrineRepository::class);
        $doctrineRepository = $reflectionClass->newInstanceWithoutConstructor();

        $parentReflectionClass = $reflectionClass->getParentClass();

        static::assertNotFalse($parentReflectionClass, 'DoctrineRepository must keep extending EntityRepository, which is where `class` lives');

        $classMetadataProperty = $parentReflectionClass->getProperty('class');
        $classMetadataProperty->setValue($doctrineRepository, $classMetadata);

        return $doctrineRepository;
    }
}
