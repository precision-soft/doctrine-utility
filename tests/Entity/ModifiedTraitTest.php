<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Utility\Test\Entity;

use DateTime;
use PHPUnit\Framework\TestCase;
use PrecisionSoft\Doctrine\Utility\Entity\ModifiedTrait;

/**
 * @internal
 */
final class ModifiedTraitTest extends TestCase
{
    /** @phpstan-var object */
    private object $entity;

    protected function setUp(): void
    {
        $this->entity = new class {
            use ModifiedTrait;
        };
    }

    public function testGetModifiedDefaultIsNull(): void
    {
        static::assertNull($this->entity->getModified());
    }

    public function testSetModifiedAndGetModified(): void
    {
        $dateTime = new DateTime('2024-01-15 10:30:00');
        $returnValue = $this->entity->setModified($dateTime);

        static::assertSame($dateTime, $this->entity->getModified());
        static::assertSame($this->entity, $returnValue);
    }

    public function testSetModifiedReturnsSelf(): void
    {
        $dateTime = new DateTime();
        $returnValue = $this->entity->setModified($dateTime);

        static::assertInstanceOf($this->entity::class, $returnValue);
    }

    public function testSetModifiedOverwritesPreviousValue(): void
    {
        $firstDateTime = new DateTime('2024-01-01');
        $secondDateTime = new DateTime('2024-06-15');

        $this->entity->setModified($firstDateTime);
        static::assertSame($firstDateTime, $this->entity->getModified());

        $this->entity->setModified($secondDateTime);
        static::assertSame($secondDateTime, $this->entity->getModified());
    }

    public function testUpdateModifiedTimestamp(): void
    {
        static::assertNull($this->entity->getModified());

        $beforeDateTime = new DateTime();
        $this->entity->updateModifiedTimestamp();
        $afterDateTime = new DateTime();

        $modifiedDateTime = $this->entity->getModified();

        static::assertInstanceOf(DateTime::class, $modifiedDateTime);
        static::assertGreaterThanOrEqual($beforeDateTime, $modifiedDateTime);
        static::assertLessThanOrEqual($afterDateTime, $modifiedDateTime);
    }

    public function testUpdateModifiedTimestampOverwritesPreviousValue(): void
    {
        $oldDateTime = new DateTime('2020-01-01');
        $this->entity->setModified($oldDateTime);

        $this->entity->updateModifiedTimestamp();

        $modifiedDateTime = $this->entity->getModified();
        static::assertNotSame($oldDateTime, $modifiedDateTime);
        static::assertInstanceOf(DateTime::class, $modifiedDateTime);
    }
}
