<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Test\Repository;

use Doctrine\ORM\Mapping\ClassMetadata;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use PrecisionSoft\Doctrine\Utility\Repository\DoctrineRepository;
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

    private function createDoctrineRepositoryWithMetadata(ClassMetadata $classMetadata): DoctrineRepository
    {
        $reflectionClass = new ReflectionClass(DoctrineRepository::class);
        $doctrineRepository = $reflectionClass->newInstanceWithoutConstructor();

        $classMetadataProperty = $reflectionClass->getParentClass()->getProperty('class');
        $classMetadataProperty->setAccessible(true);
        $classMetadataProperty->setValue($doctrineRepository, $classMetadata);

        return $doctrineRepository;
    }
}
